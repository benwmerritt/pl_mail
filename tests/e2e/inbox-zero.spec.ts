import type { Page } from "@playwright/test";
import { test, expect } from "./support/test";
import { ajaxPost, seed } from "./support/config";
import { rowAction } from "./support/rows";

/**
 * The picture an emptied inbox turns into, and the week under it (BMAIL-15).
 *
 * What only a browser can say: the picture stays out of the way while there is
 * mail, comes up the moment the last row is archived (before the server has
 * answered), and today is counted and still counted after a reload.
 *
 * REMINDER: run `php bin/console asset-map:compile` after any JS change, or
 * Playwright reads the previous build.
 */

const ROWS = '#message-list li[data-controller~="mail--message-row"]';

test.beforeEach(() => {
    seed("seed-mail");
});

// These tests archive every fixture thread in the inbox.
test.afterAll(() => {
    seed("seed-mail");
});

async function rowIds(page: Page): Promise<number[]> {
    return page.locator(ROWS).evaluateAll(
        (rows) => rows.map((row) => Number(row.id.replace("thread_", ""))),
    );
}

test("archiving the last conversation shows the picture and counts today", async ({ page }) => {
    await page.goto("/mail/inbox");

    const picture = page.locator("#message-list .inbox-zero");
    await expect(page.locator(ROWS).first()).toBeVisible();
    await expect(picture, "hidden while there is mail").toBeHidden();

    const ids = await rowIds(page);
    expect(ids.length, "the fixture inbox has mail").toBeGreaterThan(0);

    if (ids.length > 1) {
        const archived = await ajaxPost(page, "/status/bulk/archive", { ids: ids.slice(1), scope: "inbox", value: "" });
        expect(archived.ok(), "archiving the rest of the fixture failed").toBe(true);
        await page.reload();
    }

    await expect(page.locator(ROWS)).toHaveCount(1);
    await expect(picture).toBeHidden();

    const recorded = page.waitForResponse(
        (response) => response.url().endsWith("/mail/inbox-zero") && "POST" === response.request().method(),
    );

    await rowAction(page.locator(ROWS).first(), /^Archive$/);

    await expect(picture).toBeVisible();
    await expect(picture.getByText("Inbox zero.")).toBeVisible();
    expect((await recorded).status()).toBe(204);

    const today = picture.locator('[data-mail--inbox-zero-target="today"]');
    await expect(today).toHaveAttribute("data-reached", "");
    await expect(picture.locator('[data-mail--inbox-zero-target="caption"]')).toHaveText(/^Clear on [1-7] of the last 7 days$/);
    await expect(picture.locator(".inbox-zero-dot")).toHaveCount(7);

    await page.reload();

    await expect(picture).toBeVisible();
    await expect(today, "today is kept by the server, not just drawn").toHaveAttribute("data-reached", "");
});

test("an empty tab other than Primary shows the picture without the week", async ({ page }) => {
    // seed-mail files every fixture thread under Primary, so Updates is empty.
    const posts: string[] = [];
    page.on("request", (request) => {
        if (request.url().endsWith("/mail/inbox-zero")) {
            posts.push(request.method());
        }
    });

    await page.goto("/mail/inbox?tab=updates");

    const picture = page.locator("#message-list .inbox-zero");

    await expect(page.locator(ROWS)).toHaveCount(0);
    await expect(picture).toBeVisible();
    await expect(picture.getByText("Nothing here.")).toBeVisible();
    await expect(picture.locator(".inbox-zero-dot")).toHaveCount(0);
    expect(posts, "only Primary counts towards the week").toEqual([]);
});
