# Collection metric definitions (authoritative, pre-implementation)

These definitions bind Phase 5/6 implementation and tests. All amounts are integer
minor units. "Today" is the current local date in **Asia/Dhaka**; a local date D spans
`[D 00:00, D+1 00:00)` Asia/Dhaka, converted to UTC for queries.

## Building blocks

* **Receivable invoice:** status ∈ {issued, partially_paid, paid, overdue}; never draft or void.
* **Schedule line (`collection_schedules`):** every receivable invoice has ≥ 1 line. A single-due-date
  invoice has one line (amount = grand total, due_date = invoice due date). Installment invoices have
  one line per installment. Lines sum exactly to the invoice total (enforced on issue).
* **Line outstanding** = `line.amount − Σ allocations applied to that line` (allocations are applied to
  an invoice's lines in due-date order unless the user targets a line). Never negative.
* **Promise (`payment_promises`):** optional `(line, promised_date, promised_amount)`; only the latest
  active promise per line counts; a promise never increases the expected amount beyond line outstanding.

## Metrics

1. **Due Today** = Σ line outstanding where `line.due_date = today`.
2. **Expected Collection Today** — per line, the *effective expected date* is:
   1. the latest active promise's `promised_date` if one exists, else
   2. a rescheduled expected date if set, else
   3. `line.due_date`.

   Expected Today = Σ min(line outstanding, promised_amount or line outstanding) over lines whose
   effective expected date = today. Each line contributes **once** (no double counting between due
   date, reschedule and promise). Overdue lines without a promise for today are *not* included
   (they appear in Overdue and in the queue's "Overdue" filter).
3. **Collected Today** = Σ payment amounts with `received_at` in today − Σ reversals/refunds whose
   `reversed_at` is in today (reporting rule: reversals reduce the day they are recorded, not the
   original day). Unallocated advances count as collected.
4. **Remaining Expected Today** = Σ over the lines in (2) of their *current* outstanding after
   today's allocations, capped at their expected amount.
5. **Total Outstanding** = Σ line outstanding over receivable invoices (incl. not yet due)
   + customer opening receivables not yet settled − unallocated advances are **not** netted (shown separately).
6. **Total Overdue** = Σ line outstanding where `line.due_date < today`.
7. **Upcoming Collection** (range R) = Σ line outstanding where `line.due_date ∈ R`, R in the future.
8. **Collection Rate (month)** = Collected in month ÷ (Outstanding at month start + Invoiced in month due within month). Displayed with its definition.
9. **Receivables aging** buckets by days past `line.due_date`: current, 1–30, 31–60, 61–90, 90+.

## Invariants (tested when implemented)

* Expected Today ≤ Σ outstanding of lines with effective date today.
* Due Today + Overdue + Upcoming(all future) = Total Outstanding (excluding opening balances).
* Recording a payment and allocating it never changes Collected for another day.
