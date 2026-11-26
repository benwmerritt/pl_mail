<?php

declare(strict_types=1);

namespace App\Tests\Controller\Calendar;

use App\Domain\Enum\Calendar\CalendarRole;
use App\Entity\Calendar\Calendar;
use App\Entity\User\User;
use App\Service\Calendar\CalendarSets;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Calendar sets, driven through the calendar toolbar's menu as a person would:
 * tick calendars, save them as a set, pick a set, and come back to the page.
 */
final class CalendarSetsTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Connection $connection;
    private int $userId;

    /** @var array<string, int> calendar name => id */
    private array $ids = [];

    protected function tearDown(): void
    {
        if (true === isset($this->connection) && true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testTheMenuListsEveryCalendarAndWhichAreShowing(): void
    {
        $this->signIn();
        $this->post('toggle', 'Gym');

        $menu = $this->menu();

        self::assertSame(['Personal', 'Work', 'Gym'], $menu->filter('[data-calendar-toggle]')->each(
            static fn (Crawler $row): string => (string) $row->attr('data-calendar-toggle'),
        ));
        self::assertSame(['true', 'true', 'false'], $menu->filter('[data-calendar-toggle]')->each(
            static fn (Crawler $row): string => (string) $row->attr('aria-checked'),
        ));
    }

    public function testSavingWhatIsShowingMakesASetThatIsThenOn(): void
    {
        $this->signIn();
        $this->post('toggle', 'Gym');
        $this->save('Work');

        self::assertSame('Work', trim($this->menu()->filter('[data-calendar-set-label]')->text()));
        self::assertSame(
            [$this->ids['Personal'], $this->ids['Work']],
            $this->sets()[0]->calendarIds,
        );
    }

    public function testPickingASetShowsItsCalendarsAndHidesTheRest(): void
    {
        $this->signIn();
        $this->post('toggle', 'Work');
        $this->save('Gym only');
        $this->post('toggle', 'Work');

        $this->apply('Gym only');

        self::assertSame(['Personal' => true, 'Work' => false, 'Gym' => true], $this->visibility());
    }

    /** The default stays shown, as Settings → Calendars already insists. */
    public function testTheDefaultCalendarStaysShown(): void
    {
        $this->signIn();
        $this->post('toggle', 'Personal');

        self::assertTrue($this->visibility()['Personal']);
        self::assertNotNull($this->menu()->filter('[data-calendar-toggle="Personal"]')->attr('disabled'));
    }

    /** Ticking by hand after picking a set is no longer that set, and offers to update it. */
    public function testChangingTheCalendarsOffersToUpdateTheSet(): void
    {
        $this->signIn();
        $this->post('toggle', 'Gym');
        $this->save('Work');
        $this->post('toggle', 'Gym');

        $menu = $this->menu();
        self::assertSame('Custom', trim($menu->filter('[data-calendar-set-label]')->text()));

        $this->client->submit($menu->filter('form[action$="/update"]')->form());

        self::assertSame(3, count($this->sets()[0]->calendarIds));
        self::assertSame('Work', trim($this->menu()->filter('[data-calendar-set-label]')->text()));
    }

    public function testASetCanBeRenamedAndDeleted(): void
    {
        $this->signIn();
        $this->save('Work');
        $id = $this->sets()[0]->id;

        $this->client->request('POST', "/calendar/sets/{$id}/rename", ['_token' => $this->token(), 'name' => '  Day job ']);
        self::assertSame('Day job', $this->sets()[0]->name);

        $this->client->request('POST', "/calendar/sets/{$id}/delete", ['_token' => $this->token()]);
        self::assertSame([], $this->sets());
    }

    public function testItOnlyEverReturnsWithinPlMail(): void
    {
        $this->signIn();

        $this->client->request('POST', '/calendar/sets', ['_token' => $this->token(), 'name' => 'Work', 'return' => '//evil.example/']);

        self::assertResponseRedirects('/calendar', 303);
    }

    public function testAnotherPersonsCalendarCannotBeToggled(): void
    {
        $this->signIn();
        $token = $this->token();

        $stranger        = $this->user('stranger');
        $theirs          = $this->calendar($stranger, 'Theirs', false);
        $this->em->flush();

        $this->client->request('POST', "/calendar/sets/calendar/{$theirs->id}/toggle", ['_token' => $token]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testEveryActionNeedsTheToken(): void
    {
        $this->signIn();

        $this->client->request('POST', '/calendar/sets', ['_token' => 'nope', 'name' => 'Work']);

        self::assertResponseStatusCodeSame(403);
        self::assertSame([], $this->sets());
    }

    // ── fixture ───────────────────────────────────────────────────────────

    private function menu(): Crawler
    {
        $crawler = $this->client->request('GET', '/calendar/week/2026-11-27');
        self::assertResponseIsSuccessful();

        return $crawler->filter('[data-calendar-sets]');
    }

    private function token(): string
    {
        return (string) $this->menu()->filter('input[name="_token"]')->attr('value');
    }

    private function post(string $action, string $calendar): void
    {
        $this->client->request('POST', "/calendar/sets/calendar/{$this->ids[$calendar]}/{$action}", [
            '_token' => $this->token(),
            'return' => '/calendar/week/2026-11-27',
        ]);
        self::assertResponseRedirects('/calendar/week/2026-11-27', 303);
    }

    private function save(string $name): void
    {
        $this->client->request('POST', '/calendar/sets', ['_token' => $this->token(), 'name' => $name]);
        self::assertResponseRedirects();
    }

    private function apply(string $name): void
    {
        $form = $this->menu()->filter(sprintf('[data-calendar-set="%s"]', $name))->closest('form');
        self::assertNotNull($form);
        $this->client->submit($form->form());
    }

    /** @return list<\App\Domain\DTO\Calendar\CalendarSet> */
    private function sets(): array
    {
        return static::getContainer()->get(CalendarSets::class)->all($this->stored());
    }

    /** @return array<string, bool> */
    private function visibility(): array
    {
        $this->em->clear();
        $out = [];

        foreach ($this->ids as $name => $id) {
            $out[$name] = (bool) $this->em->find(Calendar::class, $id)?->isVisible;
        }

        return $out;
    }

    private function stored(): User
    {
        $this->em->clear();

        return $this->em->find(User::class, $this->userId) ?? throw new \RuntimeException('fixture user gone');
    }

    private function signIn(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();

        $container        = static::getContainer();
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);
        $this->connection->beginTransaction();

        $user = $this->user('sets');

        foreach (['Personal' => true, 'Work' => false, 'Gym' => false] as $name => $isDefault) {
            $this->calendar($user, $name, $isDefault);
        }

        $this->em->flush();

        $this->userId = (int) $user->id;
        foreach ($this->em->getRepository(Calendar::class)->findBy(['usr' => $user]) as $calendar) {
            $this->ids[$calendar->name] = (int) $calendar->id;
        }

        $this->client->loginUser($user);
    }

    private function user(string $prefix): User
    {
        $user            = new User();
        $user->email     = $prefix . '-' . uniqid('', true) . '@example.test';
        $user->nameFirst = 'Sets';
        $user->nameLast  = 'Fixture';
        $user->roles     = ['ROLE_USER'];
        $user->password  = '$2y$04$abcdefghijklmnopqrstuvwxyz0123456789ABCDEFGHIJKLMNOP';
        $this->em->persist($user);

        return $user;
    }

    private function calendar(User $user, string $name, bool $isDefault): Calendar
    {
        static $order = 0;

        $calendar            = new Calendar();
        $calendar->usr       = $user;
        $calendar->name      = $name;
        $calendar->role      = $isDefault ? CalendarRole::Default : CalendarRole::Custom;
        $calendar->timeZone  = 'UTC';
        $calendar->isDefault = $isDefault;
        $calendar->sortOrder = ++$order;
        $this->em->persist($calendar);

        return $calendar;
    }
}
