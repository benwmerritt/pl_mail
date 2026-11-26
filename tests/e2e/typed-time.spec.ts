import { test, expect, type Page } from "./support/test";
import { APP_TIMEZONE, INBOX_SUBJECTS, mailRow, seed } from "./support/config";
import { rowAction } from "./support/rows";

/**
 * A time typed into the snooze and send-later menus (BMAIL-12): "tue 3pm",
 * "2 days", "next month", in English or German. What it was read as is shown
 * as one more option, and Enter takes it.
 *
 * The readings are checked on a browser clock fixed to a Wednesday morning in
 * Berlin, so each has one right answer. Nothing is posted on that clock: the
 * server keeps the real time, and a fake one would put its own bounds on the
 * other side of the test. The ones that do post run on the live clock.
 *
 * The browser is put in the zone the send menu reads in, so the two menus,
 * one on the browser's clock and one on the configured zone, agree here.
 */
test.use({ timezoneId: APP_TIMEZONE });

test.beforeEach(() => {
    seed("seed-mail", "clear-drafts");
});

/** Wednesday 2 December 2026, 10:00 in Berlin. */
const WEDNESDAY = new Date("2026-12-02T09:00:00Z");

/** What is typed, and the instant it must be read as from WEDNESDAY. */
const READINGS: Array<[string, string]> = [
    ["tue 3pm", "2026-12-08T14:00:00.000Z"],
    ["di 15 Uhr", "2026-12-08T14:00:00.000Z"],
    ["2 days", "2026-12-04T07:00:00.000Z"],
    ["2 Tage", "2026-12-04T07:00:00.000Z"],
    ["next month", "2027-01-01T07:00:00.000Z"],
    ["nächsten Monat", "2027-01-01T07:00:00.000Z"],
    ["in 3 hours", "2026-12-02T12:00:00.000Z"],
    ["tomorrow 9:30", "2026-12-03T08:30:00.000Z"],
    ["morgen früh", "2026-12-03T07:00:00.000Z"],
    ["5pm", "2026-12-02T16:00:00.000Z"],
    ["in 2 days at 9", "2026-12-04T08:00:00.000Z"],
    ["15h30", "2026-12-02T14:30:00.000Z"],
    ["Montag morgen", "2026-12-07T07:00:00.000Z"],
    ["3 p.m.", "2026-12-02T14:00:00.000Z"],
    ["15:30 am Freitag", "2026-12-04T14:30:00.000Z"],
];

/** A send to the server, as opposed to the draft saves around it. */
const SENT = /\/compose\/send(\/\d+)?$/;

async function openSend(page: Page) {
    await page.goto("/mail/inbox");
    await page.getByRole("link", { name: "Compose" }).first().click();

    const pill = page.locator('#compose_dock [data-compose-send-pill="bar"]');
    await pill.getByRole("button", { name: "Send options" }).click();

    const menu = pill.locator('[data-ui--dropdown-target="menu"]');
    await expect(menu).toBeVisible();

    return menu;
}

async function openSnooze(page: Page) {
    await rowAction(mailRow(page, INBOX_SUBJECTS.read), /snooze/i);

    const menu = page.locator("#row-snooze-menu");
    await expect(menu).toBeVisible();

    return menu;
}

test.describe("a typed time", () => {
    test("the snooze menu reads English and German, and says when it cannot", async ({ page }) => {
        await page.clock.setFixedTime(WEDNESDAY);
        await page.goto("/mail/inbox");

        const menu    = await openSnooze(page);
        const field   = menu.getByRole("textbox");
        const reading = menu.locator("[data-mail--snooze-menu-target='reading']");

        // Opened, and already typing: `b` and the time is all it takes.
        await expect(field).toBeFocused();

        for (const [typed, until] of READINGS) {
            await field.fill(typed);
            await expect(reading, typed).toHaveAttribute("data-mail--row-menus-until-param", until);
        }

        // What the option says, not only what it carries: far enough out to name the date.
        await field.fill("tue 3pm");
        await expect(reading).toBeVisible();
        await expect(reading).toContainText(/Tue.*8/);

        await field.fill("today 7am");
        await expect(reading).toBeHidden();
        await expect(menu.getByText("That time has already passed.")).toBeVisible();

        await field.fill("whenever");
        await expect(menu.getByText(/Not a time I can read/)).toBeVisible();

        // The arrows walk the options and come back round to the field.
        await field.press("ArrowDown");
        await expect(field).not.toBeFocused();
        await page.keyboard.press("ArrowUp");
        await expect(field).toBeFocused();
    });

    test("Enter in the snooze menu snoozes to what was read", async ({ page }) => {
        await page.goto("/mail/inbox");

        const menu = await openSnooze(page);

        await menu.getByRole("textbox").fill("2 days");
        await expect(menu.locator("[data-mail--snooze-menu-target='reading']")).toBeVisible();
        await menu.getByRole("textbox").press("Enter");

        await expect(menu).toBeHidden();
        await expect(mailRow(page, INBOX_SUBJECTS.read)).toHaveCount(0);
    });

    test("the send menu reads one in the configured zone", async ({ page }) => {
        await page.clock.setFixedTime(WEDNESDAY);

        const menu    = await openSend(page);
        const field   = menu.getByRole("textbox");
        const reading = menu.locator("[data-compose--schedule-target='reading']");

        await expect(field).toBeFocused();

        await field.fill("tomorrow 9am");
        await expect(reading).toHaveAttribute("data-schedule-at", "2026-12-03T09:00");
        await expect(reading).toContainText(/Thu.*3/);

        await field.fill("whenever");
        await expect(reading).toBeHidden();
        await expect(menu.getByText(/Not a time I can read/)).toBeVisible();

        // Escape from the field hands the focus back to the button, not to a hidden menu.
        await page.keyboard.press("Escape");
        await expect(menu).toBeHidden();
        await expect(page.locator('#compose_dock [data-compose-send-pill="bar"]').getByRole("button", { name: "Send options" })).toBeFocused();
    });

    test("Enter in the send menu schedules what was read, and never sends", async ({ page }) => {
        const sends: string[] = [];
        page.on("request", (request) => {
            if ("POST" === request.method() && SENT.test(new URL(request.url()).pathname)) {
                sends.push(request.url());
            }
        });

        await page.goto("/mail/inbox");
        await page.getByRole("link", { name: "Compose" }).first().click();

        // A window that could be sent, so a stray Enter would be one.
        const to = page.locator("#compose_dock .ts-control input").first();
        await to.fill("typed-time@example.test");
        await to.press("Enter");
        await page.locator('#compose_dock [data-compose--compose-target="subject"]').fill("Typed time");
        await page.locator('#compose_dock [data-compose--compose-toolbar-target="editor"]').fill("Held until a typed time.");

        const pill = page.locator('#compose_dock [data-compose-send-pill="bar"]');
        await pill.getByRole("button", { name: "Send options" }).click();

        const field = pill.locator('[data-ui--dropdown-target="menu"]').getByRole("textbox");

        await field.fill("whenever");
        await field.press("Enter");
        await field.press("ControlOrMeta+Enter");
        await expect(page.locator("#compose_dock .compose-window")).toHaveCount(1);

        await field.fill("tomorrow 9am");
        await field.press("Enter");

        await expect(page.locator("#toast-region").getByText(/Scheduled for/)).toBeVisible({ timeout: 10_000 });
        await expect(page.locator("#compose_dock .compose-window")).toHaveCount(0);
        expect(sends).toEqual([]);
    });
});
