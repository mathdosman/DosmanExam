<?php
/**
 * Hook callbacks for local_dosman_ujian
 *
 * @package    local_dosman_ujian
 * @copyright  2024 dosman_ujian
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$callbacks = [
    [
        'hook'     => core\hook\output\before_http_headers::class,
        'callback' => local_dosman_ujian\hook\output\before_http_headers::class . '::callback',
        'priority' => 500,
    ],
    [
        'hook'     => core\hook\output\before_footer_html_generation::class,
        'callback' => local_dosman_ujian\hook\output\before_footer::class . '::callback',
        'priority' => 500,
    ],
];
