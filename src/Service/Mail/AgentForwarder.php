<?php

declare(strict_types=1);

namespace App\Service\Mail;

use App\Controller\Mail\ComposeController;
use App\Domain\DTO\Mail\ForwardAgent;
use App\Domain\Enum\Crypto\RecipientPolicy;
use App\Entity\Mail\Account;
use App\Entity\Mail\Message;
use App\Entity\User\User;
use App\Infrastructure\Messaging\Message\SendMessageMessage;
use App\Service\Crypto\RecipientKeys;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

/**
 * "Forward to agent": the forward the composer would have built, addressed to
 * one of the person's agents, with their note above the quote, sent.
 *
 * Every step is the composer's own, in the composer's order — the draft from
 * ReplyDraftBuilder::forward(), the files from ForwardedAttachments, the save
 * through DraftPersister, the same SendMessageMessage behind the same delay —
 * so a hand-off is an ordinary forward in every way that matters afterwards:
 * it sits in Sent, it threads, and its Undo reopens it in the compose dock.
 *
 * Forwards only the browser composer can make are refused rather than sent:
 * encrypted mail, whose quote it decrypts, and a send it would sign or
 * encrypt — "sign plain mail", "always encrypt" for the address it goes from,
 * or an agent whose address has a key or its own Always policy. Sent from
 * here, the first would carry a marker where the mail should be and the rest
 * would go out in the clear without saying so.
 */
final readonly class AgentForwarder
{
    public const string REFUSED_ENCRYPTED = 'encrypted';

    public const string REFUSED_SEALED = 'sealed';

    public function __construct(
        private ReplyDraftBuilder      $replyDrafts,
        private DraftPersister         $drafts,
        private ForwardedAttachments   $forwardedAttachments,
        private ComposeWindow          $window,
        private SenderResolver         $senders,
        private MessageBusInterface    $bus,
        private EntityManagerInterface $entityManager,
        private RecipientKeys          $recipientKeys,
    ) {
    }

    /**
     * Why this mail cannot be forwarded to an agent from here, as one of the
     * REFUSED_ constants, or null when it can.
     */
    public function refusal(Message $original, User $user): ?string
    {
        if (true === $original->crypto?->isEncrypted()) {
            return self::REFUSED_ENCRYPTED;
        }

        $account = $this->sendingAccount($original, $user);

        if (true === self::sealsWhatItSends($user, (string) ($account->displayAddress ?? $account->email ?? ''))) {
            return self::REFUSED_SEALED;
        }

        return null;
    }

    /**
     * Whether the composer would encrypt or sign mail to this agent, which a
     * forward from here cannot. The keys plMail already holds, without looking
     * one up: the composer's lookup happens as an address is typed, and an
     * agent picked from a list was never typed.
     */
    public function sealsFor(User $user, ForwardAgent $agent): bool
    {
        foreach ($this->recipientKeys->describe($user, [$agent->address], false) as $recipient) {
            if (RecipientPolicy::Always->value === $recipient['encrypt'] || RecipientPolicy::Always->value === $recipient['sign']) {
                return true;
            }

            if (RecipientPolicy::Never->value !== $recipient['encrypt'] && (null !== $recipient['key'] || null !== $recipient['smime'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Build, save and queue the forward. Answers the draft, now on its way, or
     * null when there is nothing to send it from or the original cannot be
     * forwarded from here.
     */
    public function forward(Message $original, ForwardAgent $agent, string $note, User $user): ?Message
    {
        $account = $this->sendingAccount($original, $user);

        if (null === $account || null !== $this->refusal($original, $user) || true === $this->sealsFor($user, $agent)) {
            return null;
        }

        $draft              = $this->replyDrafts->forward($original);
        $draft->toAddresses = [['name' => $agent->name, 'address' => $agent->address]];
        $draft->bodyHtml    = self::withNote($note, (string) $draft->bodyHtml);

        $sender = $this->senders->addressFor($this->senders->tokenFor($draft, $account), $account, $user);

        if (true === self::sealsWhatItSends($user, $sender)) {
            return null;
        }

        // Saved before the files are copied and again after, as the composer
        // does across opening a forward and pressing Send: the bytes are
        // bucketed by message id, which the first save gives the draft.
        $this->drafts->save($draft, $account, $sender);

        if (true === $this->forwardedAttachments->has($original)) {
            $this->forwardedAttachments->copy($original, $draft);
            $this->drafts->save($draft, $account, $sender);
        }

        $draft->bodyText = self::wholeText((string) $draft->bodyHtml);
        $this->entityManager->flush();

        $this->bus->dispatch(
            new SendMessageMessage((int) $draft->id, $draft->submissionSendAt),
            [new DelayStamp(ComposeController::SEND_DELAY_MS)],
        );

        return $draft;
    }

    /**
     * The account the mail is in, as a forward from the composer would use,
     * unless that account only receives; then the one a new message would go
     * from.
     */
    private function sendingAccount(Message $original, User $user): ?Account
    {
        return true === $original->account->sendEnabled
            ? $original->account
            : $this->window->defaultAccountFor($user);
    }

    private static function sealsWhatItSends(User $user, string $from): bool
    {
        return true === $user->signPlainMail
            || 'always' === ($user->ownAddressEncrypt[strtolower(trim($from))] ?? null);
    }

    /**
     * The note as paragraphs, in place of the empty one the forward opens with
     * for somebody to type into. A blank line starts a new paragraph and a
     * single line break stays one.
     */
    private static function withNote(string $note, string $forward): string
    {
        $note = trim(str_replace(["\r\n", "\r"], "\n", $note));

        if ('' === $note) {
            return $forward;
        }

        $paragraphs = array_map(
            static fn (string $paragraph): string => sprintf(
                '<p>%s</p>',
                nl2br(htmlspecialchars(trim($paragraph), ENT_QUOTES, 'UTF-8'), false),
            ),
            preg_split('/\n\s*\n/', $note) ?: [$note],
        );

        $writingSpace = '<p><br></p>';

        if (true === str_starts_with($forward, $writingSpace)) {
            $forward = substr($forward, strlen($writingSpace));
        }

        return implode("\n", $paragraphs) . "\n" . ltrim($forward);
    }

    /**
     * The text/plain part with the forwarded mail in it, not only the note.
     *
     * DraftPersister::plainTextBody() keeps just the sender's own writing and
     * drops everything from the quote down, which suits a person reading the
     * HTML. An agent may read the text part alone, and the mail is the point.
     */
    private static function wholeText(string $html): ?string
    {
        $html = (string) preg_replace('#<(style|script|head)\b.*?</\1\s*>#is', '', $html);
        $html = (string) preg_replace('/\s+/', ' ', $html);
        $html = (string) preg_replace('#<div[^>]*data-quoted#i', "\n$0", $html);
        $html = (string) preg_replace('#<br\s*/?>|</(p|div|li|tr|h[1-6]|blockquote)\s*>#i', "$0\n", $html);
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = (string) preg_replace('/^[ \t]+|[ \t]+$/m', '', $text);
        $text = trim((string) preg_replace('/\n{3,}/', "\n\n", $text));

        return '' === $text ? null : $text;
    }
}
