<?php
/**
 * FFI com Structs: timespec da libc
 */

$ffi = FFI::cdef("
    struct timespec {
        long tv_sec;
        long tv_nsec;
    };
    
    int clock_gettime(int clk_id, struct timespec *tp);
", "libc.so.6");

// CLOCK_MONOTONIC = 1
$CLOCK_MONOTONIC = 1;

$ts = $ffi->new("struct timespec");

$ffi->clock_gettime($CLOCK_MONOTONIC, FFI::addr($ts));

echo "=== clock_gettime via FFI ===\n";
echo "Segundos: " . $ts->tv_sec . "\n";
echo "Nanosegundos: " . $ts->tv_nsec . "\n";
echo "Timestamp completo: " . ($ts->tv_sec + $ts->tv_nsec / 1_000_000_000) . "\n";

// Comparativo com microtime
echo "\nmicrotime(true): " . microtime(true) . "\n";