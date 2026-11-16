# Report finality and source coverage

Finality describes imported rows; it does not establish that every source or day
has reported. Admin summary/site views and publisher performance now use the same
label: missing rows await imported data, estimates await finalization, and finalized
rows are labelled as imported rows. Where the existing latest-selected-day GAM
coverage check has missing sources, the badge also says partial source coverage.
Website cards use the same imported-row wording without inventing per-card coverage.

This is presentation only. Source totals, rounding, report queries, currencies,
financial close, payouts and historical finality are unchanged. Existing coverage
still checks the latest selected reporting day per active GAM source, not every
source engine or every day in the range. No whole-platform completeness is claimed.

Validation: ReportDataStateTest, ReportPerformanceMetricsTest,
PublisherFinanceExperienceTest and ReportingFinancialSystemTest: 38 tests / 581
assertions passed locally on PHP 8.4. Includes no-data/estimate/partial states,
financial history, multiple sources, currency separation, ownership, CSV output,
statement balances and partial payouts. Required CI and deployment remain pending.
