<?php
// This file is part of KAISER Proctor for Moodle.
//
// Core proctoring service: face enrolment, identity checks, evidence storage
// and the browser-side attention modules. Quiz gating lives in the companion
// quizaccess_kaiproctor plugin.

defined('MOODLE_INTERNAL') || die();

$plugin->component = 'local_kaiproctor';
$plugin->version   = 2026081004;
$plugin->requires  = 2024100700; // Moodle 4.5 LTS.
$plugin->maturity  = MATURITY_ALPHA;
$plugin->release   = '0.1.0-dev';
