<?php

namespace block_sharing_cart\external\backup;

// @codeCoverageIgnoreStart
defined('MOODLE_INTERNAL') || die();

// @codeCoverageIgnoreEnd

use block_sharing_cart\app\factory;
use block_sharing_cart\app\item\entity;
use core_external\external_api;
use core_external\external_description;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use core_courseformat\base as course_format;

class section_into_sharing_cart extends external_api
{
    public static function execute_parameters(): external_function_parameters
    {
        return new external_function_parameters([
            'section_id' => new external_value(PARAM_INT, '', VALUE_REQUIRED),
            'settings' => new external_single_structure([
                'users' => new external_value(PARAM_BOOL, 'Whether to include user data in the backup', VALUE_REQUIRED),
                'anonymize' => new external_value(
                    PARAM_BOOL, 'Whether to anonymize user data in the backup', VALUE_REQUIRED
                ),
            ], 'The settings of the item')
        ]);
    }

    public static function execute(int $section_id, array $settings): object
    {
        global $USER, $DB;

        $base_factory = factory::make();

        $params = self::validate_parameters(self::execute_parameters(), [
            'section_id' => $section_id,
            'settings' => $settings,
        ]);

        $course_id = $DB->get_field('course_sections', 'course', ['id' => $params['section_id']], MUST_EXIST);

        self::validate_context(
            \context_course::instance($course_id)
        );

        $sequence = $DB->get_field('course_sections', 'sequence', ['id' => $params['section_id']], MUST_EXIST);
        if (empty($sequence)) {
            throw new \Exception('Section is empty');
        }

        $item = $base_factory->item()->repository()->insert_section(
            $params['section_id'],
            $USER->id,
            null,
            entity::STATUS_AWAITING_BACKUP
        );

        $backup_task = $base_factory->backup()->handler()->backup_section($params['section_id'], $item, $settings);

        $format = course_get_format($course_id);
        // If this is flexsections course format, copy subsections.
        if ($format instanceof \format_flexsections) {
            // Recursively backup subsections.
            $dependency_tasks = self::backup_subsections($item, $base_factory, $format, $settings);
        }

        $return = $item->to_array();
        $return['task_id'] = $backup_task->get_id();
        $return['dependency_task_ids'] = implode(',', $dependency_tasks);

        return (object)$return;
    }

    public static function backup_subsections(entity $item, factory $base_factory, course_format $format, array $settings): array
    {
        global $USER, $DB;

        $section = $DB->get_record('course_sections', ['id' => $item->get_old_instance_id()], strictness: MUST_EXIST);
        $subsections = $format->get_subsections($section->section);
        $tasks = [];
        foreach ($subsections as $subsection) {
            $newitem = $base_factory->item()->repository()->insert_section(
                $subsection->id,
                $USER->id,
                $item->get_id(),
                entity::STATUS_AWAITING_BACKUP
            );
            $backup_task = $base_factory->backup()->handler()->backup_section($subsection->id, $newitem, $settings);
            $tasks[] = $backup_task->get_id();
            $dependency_tasks = self::backup_subsections($newitem, $base_factory, $format, $settings);
            $tasks = array_merge($tasks, $dependency_tasks);
        }
        return $tasks;
    }

    public static function execute_returns(): external_description
    {
        return new external_single_structure([
            'id' => new external_value(PARAM_INT, 'The id of the item in the sharing cart', VALUE_REQUIRED),
            'user_id' => new external_value(PARAM_INT, 'The id of the user who owns the item', VALUE_REQUIRED),
            'file_id' => new external_value(PARAM_INT, 'The id of the backup file', VALUE_REQUIRED),
            'parent_item_id' => new external_value(PARAM_INT, 'The id of the parent item', VALUE_REQUIRED),
            'old_instance_id' => new external_value(PARAM_INT, 'The old instance id', VALUE_REQUIRED),
            'task_id' => new external_value(PARAM_INT, 'The task id of backup adhoc task', VALUE_REQUIRED),
            'dependency_task_ids' => new external_value(PARAM_TEXT, 'The task ids of dependant adhoc tasks', VALUE_REQUIRED),
            'type' => new external_value(PARAM_TEXT, 'The type of the item', VALUE_REQUIRED),
            'name' => new external_value(PARAM_TEXT, 'The name of the item', VALUE_REQUIRED),
            'status' => new external_value(PARAM_INT, 'The status of the item', VALUE_REQUIRED),
            'timecreated' => new external_value(PARAM_INT, 'The time the item was created', VALUE_REQUIRED),
            'timemodified' => new external_value(PARAM_INT, 'The time the item was last modified', VALUE_REQUIRED),
        ]);
    }
}
