import { Controller } from "@hotwired/stimulus";
import { csrfToken } from "../../csrf.js";

/** A conversation still standing in the list: rows on their way out carry `hidden`. */
const STANDING_ROW = "li[data-controller~='mail--message-row']:not([hidden])";

/**
 * Counts today towards the inbox-zero week once the inbox stands empty (BMAIL-15).
 *
 * The picture itself shows by CSS alone (app.css, `.inbox-zero`). The list
 * reaches empty in the browser, when the last row is archived and hidden ahead
 * of the server's answer (optimistic_rows.js), so this watches the rows rather
 * than trusting what the page arrived with. A hidden row is a row on its way
 * out, and counts as gone.
 *
 * Without a url value (a tab other than Primary) it draws nothing and records
 * nothing. Recording is idempotent server-side; `#recorded` only saves the
 * repeat POSTs every later mutation of the list would otherwise send.
 */
export default class extends Controller {
    static values = { url: String, days: String };
    static targets = ["today", "caption"];

    #observer = null;
    #recorded = false;

    connect() {
        if ("" === this.urlValue || null === this.element.parentElement) {
            return;
        }

        this.#observer = new MutationObserver(() => this.#check());
        this.#observer.observe(this.element.parentElement, {
            childList: true,
            subtree: true,
            attributes: true,
            attributeFilter: ["hidden"],
        });

        this.#check();
    }

    disconnect() {
        this.#observer?.disconnect();
    }

    #check() {
        if (true === this.#recorded || null !== this.element.parentElement.querySelector(STANDING_ROW)) {
            return;
        }

        this.#recorded = true;
        this.#markToday();

        fetch(this.urlValue, {
            method: "POST",
            headers: { "X-CSRF-Token": csrfToken(), "X-Requested-With": "XMLHttpRequest" },
            credentials: "same-origin",
        }).catch(() => {
            // The day goes unrecorded; the next empty inbox today records it.
            this.#recorded = false;
        });
    }

    #markToday() {
        if (false === this.hasTodayTarget || this.todayTarget.hasAttribute("data-reached")) {
            return;
        }

        this.todayTarget.setAttribute("data-reached", "");

        if (true === this.hasCaptionTarget) {
            const reached = this.element.querySelectorAll(".inbox-zero-dot[data-reached]").length;
            const caption = this.daysValue.replace("__COUNT__", String(reached));

            this.captionTarget.textContent = caption;
            this.todayTarget.closest("[role='img']")?.setAttribute("aria-label", caption);
        }
    }
}
