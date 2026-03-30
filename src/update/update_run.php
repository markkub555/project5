<?php
require_once '../config/db.php';
require_once __DIR__ . '/status_update_helper.php';

handleStatusUpdate($conn, 'run_test', 'run_test_note', ['F']);
