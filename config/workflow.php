<?php

return [
  /*
  |--------------------------------------------------------------------------
  | Spreadsheet import chunk size (rows per queue job)
  |--------------------------------------------------------------------------
  */
  'import_chunk_size' => (int) env('WORKFLOW_IMPORT_CHUNK_SIZE', 250),

  /*
  |--------------------------------------------------------------------------
  | Enrichment dispatch batch size
  |--------------------------------------------------------------------------
  */
  'enrichment_dispatch_batch' => (int) env('WORKFLOW_ENRICHMENT_DISPATCH_BATCH', 100),

  /*
  |--------------------------------------------------------------------------
  | Seconds between enrichment dispatch waves (0 = no delay)
  |--------------------------------------------------------------------------
  */
  'enrichment_dispatch_delay' => (int) env('WORKFLOW_ENRICHMENT_DISPATCH_DELAY', 0),

  /*
  |--------------------------------------------------------------------------
  | How often to run completion checks during enrichment (every N leads)
  |--------------------------------------------------------------------------
  */
  'enrichment_completion_check_every' => (int) env('WORKFLOW_ENRICHMENT_COMPLETION_CHECK_EVERY', 5),

  /*
  |--------------------------------------------------------------------------
  | Use column mapping only during import (skip Gemini row formatting)
  |--------------------------------------------------------------------------
  */
  'use_manual_import_mapping' => filter_var(env('WORKFLOW_FAST_IMPORT_MAPPING', true), FILTER_VALIDATE_BOOL),

  /*
  |--------------------------------------------------------------------------
  | Skip duplicate-phone checks against other imports in the workspace
  | (only dedupe repeated phones within the same file import batch)
  |--------------------------------------------------------------------------
  */
  'skip_cross_import_phone_dedup' => filter_var(env('WORKFLOW_SKIP_CROSS_IMPORT_PHONE_DEDUP', false), FILTER_VALIDATE_BOOL),

  /*
  |--------------------------------------------------------------------------
  | Skip all phone-based duplicate discards during import (import every row)
  | Default false: duplicate US phones in the workspace are skipped on import.
  |--------------------------------------------------------------------------
  */
  'skip_phone_dedup' => filter_var(env('WORKFLOW_SKIP_PHONE_DEDUP', false), FILTER_VALIDATE_BOOL),
];
