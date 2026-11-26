import { test, expect, type Page } from "./support/test";
import { INBOX_SUBJECTS, mailRow, seed } from "./support/config";
import { rowAction } from "./support/rows";

/**
 * A time typed into the snooze and send-later menus (BMAIL-12): "tue 3pm",
 * "2 days", "next month", in English or German. What it was read as is shown
 * as one more option, and Enter takes it.
 *
 * The readings are checked on a browser clock fixed to a Wednesday morning in
 * Berlin, so each has one right answer. Nothing is posted on that clock: the
 * server keeps the real time, and a fake one would put its own bounds on the
 * other side of the test. The two that do post run on the live clock.
 */
test.use({ timezoneId: "Europe/Berlin" });

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
];

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

        await field.fill("today 7am");
        await expect(reading).toBeHidden();
        await expect(menu.getByText("That time has already passed.")).toBeVisible();

        await field.fill("whenever");
        await expect(menu.getByText(/Not a time I can read/)).toBeVisible();
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

    test("the send menu reads one in the configured zone, and Enter there never sends", async ({ page }) => {
        await page.goto("/mail/inbox");
        await page.getByRole("link", { name: "Compose" }).first().click();

        const pill = page.locator('#compose_dock [data-compose-send-pill="bar"]');
        await pill.getByRole("button", { name: "Send options" }).click();

        const menu    = pill.locator('[data-ui--dropdown-target="menu"]');
        const field   = menu.getByRole("textbox");
        const reading = menu.locator("[data-compose--schedule-target='reading']");

        await expect(field).toBeFocused();

        // Not read: Enter says so and leaves the window as it was. Left to the
        // form, it would have been Send.
        await field.fill("whenever");
        await field.press("Enter");
        await expect(menu.getByText(/Not a time I can read/)).toBeVisible();
        await expect(page.locator("#compose_dock .compose-window")).toHaveCount(1);

        await field.fill("tomorrow 9am");
        await expect(reading).toBeVisible();
        await expect(reading).toHaveAttribute("data-schedule-at", /T09:00$/);
    });
});
