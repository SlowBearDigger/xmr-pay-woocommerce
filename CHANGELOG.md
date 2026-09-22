# Changelog

## [1.2.0] - Unreleased

- Exclude already sent refunds from the amount requested by a later refund claim. WooCommerce retains the full refund history. Accumulate subsequent manual refunds once per refund record.

- Report watch node outages without discarding the last partial-payment summary; restore the connection status after a successful height query.

- Show native watch payment progress and update the checkout QR to the remaining amount after a partial payment.

- Fix proof messages that incorrectly promised automatic confirmation updates.

- Keep widget and setup copy feedback accurate when the browser blocks clipboard access; refresh the translation template.

- Verify complete blocks and transactions and require agreement from all configured nodes.
- Preserve detected pending and locked payments during expiry; reject agent events for native-mode orders.
- Bundle the reviewed checkout widget. Agent integrations target XMRPay 2.0.0; configure the token before upgrading.
