<?php
namespace block_iomad_claude;

defined('MOODLE_INTERNAL') || die();

require_once($GLOBALS['CFG']->libdir . '/externallib.php');

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_value;

class external extends external_api {

    public static function ask_question_parameters(): external_function_parameters {
        return new external_function_parameters([
            'question' => new external_value(PARAM_TEXT, 'The question to ask', VALUE_REQUIRED),
        ]);
    }

    public static function ask_question_returns(): external_value {
        return new external_value(PARAM_RAW, 'Claude answer text');
    }

    public static function ask_question(string $question): string {
        global $DB, $USER;

        $params = self::validate_parameters(self::ask_question_parameters(), ['question' => $question]);
        $question = $params['question'];

        require_login();

        $apikey = get_config('block_iomad_claude', 'apikey');
        if (empty($apikey)) {
            throw new \moodle_exception('noapikey', 'block_iomad_claude');
        }

        // Resolve the user's company.
        // Site admins may not be in company_users — let them query the first available company.
        $cu = $DB->get_record('company_users', ['userid' => $USER->id], 'companyid', IGNORE_MULTIPLE);
        if ($cu) {
            $companyid = (int)$cu->companyid;
        } else if (is_siteadmin()) {
            $companyid = (int)$DB->get_field_sql('SELECT id FROM {company} ORDER BY id ASC LIMIT 1');
            if (!$companyid) {
                throw new \moodle_exception('nocompany', 'block_iomad_claude');
            }
        } else {
            throw new \moodle_exception('nocompany', 'block_iomad_claude');
        }

        $model  = get_config('block_iomad_claude', 'model') ?: 'claude-sonnet-4-6';
        $tools  = new iomad_tools($companyid);
        $client = new claude_client($apikey, $model, $tools);

        try {
            return $client->answer($question, $companyid);
        } catch (claude_overloaded_exception $e) {
            return '⚠️ The AI service is currently overloaded. Please wait a moment and try again.';
        } catch (\moodle_exception $e) {
            return '⚠️ ' . $e->getMessage();
        }
    }
}
