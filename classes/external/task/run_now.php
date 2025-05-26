<?php

namespace block_sharing_cart\external\task;

// @codeCoverageIgnoreStart
defined('MOODLE_INTERNAL') || die();

// @codeCoverageIgnoreEnd

use core_external\external_api;
use core_external\external_description;
use core_external\external_function_parameters;
use core_external\external_value;

class run_now extends external_api
{
    public static function execute_parameters(): external_function_parameters
    {
        return new external_function_parameters([
            'task_id' => new external_value(PARAM_INT, '', VALUE_REQUIRED),
            'dependency_task_ids' => new external_value(
                PARAM_TEXT, 'Comma-separated list of dependency task IDs', VALUE_OPTIONAL, ''
            ),
        ]);
    }

    public static function execute(
        int $task_id,
        string $dependency_task_ids = ''
    ): bool {
        global $USER, $DB;

        $params = self::validate_parameters(self::execute_parameters(), [
            'task_id' => $task_id,
            'dependency_task_ids' => $dependency_task_ids,
        ]);

        self::validate_context(
            \context_user::instance($USER->id)
        );

        if (CLI_MAINTENANCE) {
            throw new \Exception(
                get_string('sitemaintenance', 'admin')
            );
        }

        if (moodle_needs_upgrading()) {
            throw new \Exception(
                get_string('cliupgradepending', 'admin')
            );
        }

        if (!get_config('core', 'cron_enabled')) {
            throw new \Exception(
                get_string('crondisabled', 'tool_task')
            );
        }

        $taskids = explode(',', $params['dependency_task_ids']);
        // Add the main task ID to the end, so it run last.
        array_push($taskids, $params['task_id']);
        $count = 0;
        foreach ($taskids as $taskid) {
            $params = [
                'id' => (int) $taskid,
                'component' => 'block_sharing_cart',
                'faildelay' => 0,
                'timestarted' => null,
                'userid' => $USER->id
            ];
            if ($DB->record_exists('task_adhoc', $params)) {
                $count++;
                ob_start();
                \core\task\manager::run_adhoc_from_cli((int) $taskid);
                ob_end_clean();
            }
        }

        return (bool) $count;
    }

    public static function execute_returns(): external_description
    {
        return new external_value(PARAM_BOOL, '', VALUE_REQUIRED);
    }
}
