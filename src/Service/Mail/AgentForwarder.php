<?php

declare(strict_types=1);

namespace App\Service\Mail;

use App\Controller\Mail\ComposeController;
use App\Domain\DTO\Mail\ForwardAgent;
use App\Entity\Mail\Message;
use App\Entity\User\User;
use App\Infrastructure\Messaging\Message\SendMessageMessage;
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
 * Encrypted mail is refused rather than forwarded. Its quote is decrypted in
 * the browser by the composer, and a forward built here would carry a marker
 * where the mail should be.
 */
final readonly class AgentForwarder
{
    public function __construct(
        private ReplyDraftBuilder      $replyDrafts,
        private DraftPersister         $drafts,
        private ForwardedAttachments   $forwardedAttachments,
        private ComposeWindow          $window,
        private SenderResolver         $senders,
        private MessageBusInterface    $bus,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function canForward(Message $original): bool
    {
        return true !== $original->crypto?->isEncrypted();
    }

    /**
     * Build, save and queue the forward. Answers the draft, now on its way, or
     * null when there is nothing to send it from or the original cannot be
     * forwarded from here.
     */
    public function forward(Message $original, ForwardAgent $agent, string $note, User $user): ?Message
    {
        $account = $original->account ?? $this->window->defaultAccountFor($user);

        if (null === $account || false === $this->canForward($original)) {
            return null;
        }

        $draft              = $this->replyDrafts->forward($original);
        $draft->toAddresses = [['name' => $agent->name, 'address' => $agent->address]];
        $draft->bodyHtml    = self::withNote($note, (string) $draft->bodyHtml);

        $sender = $this->senders->addressFor($this->senders->tokenFor($draft, $account), $account, $user);

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
