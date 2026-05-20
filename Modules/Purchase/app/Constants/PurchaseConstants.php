<?php

namespace Modules\Purchase\Constants;

/**
 * Constants for Purchase Module
 *
 * Centralized constants to avoid magic numbers and hard-coded values
 */
class PurchaseConstants
{
    // Time constants
    public const HOURS_PER_DAY = 24;

    public const MINUTES_PER_HOUR = 60;

    public const DAYS_PER_MONTH = 30;

    // Price calculation constants
    public const DEFAULT_TAX_RATE = 15.00;

    public const DEFAULT_QUANTITY = 1.0;

    // Distance calculation constants
    public const DEFAULT_MIN_DISTANCE_KM = 5;

    public const DEFAULT_MAX_DISTANCE_KM = 100;

    public const DEFAULT_DISTANCE_KM = 50.0;

    public const AVERAGE_SPEED_KMH = 40; // Average speed in urban areas

    public const AVERAGE_SPEED_KMH_HIGHWAY = 60; // Average speed on highways

    public const LOADING_UNLOADING_HOURS = 0.5; // Fixed time for loading/unloading

    // Response rate constants
    public const DEFAULT_RESPONSE_RATE = 75.0;

    public const FAST_RESPONSE_RATE_THRESHOLD = 80;

    public const NORMAL_RESPONSE_RATE_MIN = 50;

    public const NORMAL_RESPONSE_RATE_MAX = 79;

    // Rating constants
    public const DEFAULT_RATING = 4.5;

    public const DEFAULT_SUPPLIER_RATING = 4.0;

    // Availability constants
    public const MIN_AVAILABILITY_PERCENTAGE = 60; // At least 60% availability for internal transfers

    public const FULL_AVAILABILITY_PERCENTAGE = 100;

    // Cache TTL (Time To Live) in seconds
    public const CACHE_TTL_DISTANCE = 3600; // 1 hour

    public const CACHE_TTL_PRICE_COMPARISON = 1800; // 30 minutes

    // Pagination defaults
    public const DEFAULT_PER_PAGE = 15;

    public const MAX_PER_PAGE = 100;

    // Chunk sizes for batch processing
    public const CHUNK_SIZE_SMALL = 100;

    public const CHUNK_SIZE_MEDIUM = 500;

    public const CHUNK_SIZE_LARGE = 1000;

    // Price history period (months)
    public const PRICE_HISTORY_MONTHS = 3;

    public const BRANCH_STATS_MONTHS = 6;

    // Delivery time defaults
    public const DEFAULT_DELIVERY_HOURS = 24;

    public const DEFAULT_DELIVERY_DAYS = 4;

    public const DEFAULT_INTERNAL_TRANSFER_DAYS = 1; // Internal transfers usually take 1 day

    // Processing time ranges (days)
    public const PROCESSING_STANDARD_MIN_DAYS = 3;

    public const PROCESSING_STANDARD_MAX_DAYS = 5;

    public const PROCESSING_URGENT_MIN_DAYS = 1;

    public const PROCESSING_URGENT_MAX_DAYS = 2;
}
