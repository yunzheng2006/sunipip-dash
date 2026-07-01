<?php

namespace App\Services\Ny;

/**
 * NY API 限流异常 (HTTP 429)
 *
 * Job 可以识别此异常类型决定重试策略（不同于普通业务错误）
 */
class NyApiRateLimitException extends NyApiException {}
