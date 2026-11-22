<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\Support\ExpenseCustodyConcurrencyErrorClassifier;

class ExpenseCustodyConcurrencyWorkerErrorHandlingTest extends TestCase
{
    public function test_expected_domain_conflicts_are_classified_as_conflict(): void
    {
        $this->assertTrue(
            ExpenseCustodyConcurrencyErrorClassifier::isExpectedConflict(
                new ConflictHttpException('STALE_EXPENSE_REVIEW_REVISION'),
                'approve'
            )
        );
        $this->assertTrue(
            ExpenseCustodyConcurrencyErrorClassifier::isExpectedConflict(
                new \Exception('Only pending expenses can be rejected'),
                'reject'
            )
        );
        $this->assertTrue(
            ExpenseCustodyConcurrencyErrorClassifier::isExpectedConflict(
                new RuntimeException('This custody request was already received.'),
                'confirm_receipt'
            )
        );
    }

    public function test_unexpected_errors_are_never_classified_as_expected_conflict(): void
    {
        $this->assertFalse(
            ExpenseCustodyConcurrencyErrorClassifier::isExpectedConflict(
                new RuntimeException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found'),
                'approve'
            )
        );
        $this->assertFalse(
            ExpenseCustodyConcurrencyErrorClassifier::isExpectedConflict(
                new \Exception('SQLSTATE[HY000] [2002] Connection refused'),
                'reject'
            )
        );
        $this->assertFalse(
            ExpenseCustodyConcurrencyErrorClassifier::isExpectedConflict(
                new \LogicException('Unexpected state'),
                'confirm_receipt'
            )
        );
    }

    public function test_sanitize_error_message_redacts_secrets(): void
    {
        $msg = ExpenseCustodyConcurrencyErrorClassifier::sanitizeErrorMessage(
            new RuntimeException('Connection failed password=supersecret token=abc123')
        );
        $this->assertStringContainsString('password=[REDACTED]', $msg);
        $this->assertStringContainsString('token=[REDACTED]', $msg);
        $this->assertStringNotContainsString('supersecret', $msg);
        $this->assertStringNotContainsString('abc123', $msg);
    }
}
