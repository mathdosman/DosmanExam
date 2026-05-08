<?php
/**
 * Scheduled tasks for local_dosman_ujian
 *
 * @package    local_dosman_ujian
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$tasks = [
    [
        'classname'  => 'local_dosman_ujian\task\suspend_stale_users_task',
        'blocking'   => 0,
        'minute'     => '*',
        'hour'       => '*',
        'day'        => '*',
        'month'      => '*',
        'dayofweek'  => '*',
    ],
];
