<?php
namespace block_iomad_claude;

defined('MOODLE_INTERNAL') || die();

class iomad_tools {

    private int $companyid;

    public function __construct(int $companyid) {
        $this->companyid = $companyid;
    }

    public function dispatch(string $name, array $input): array {
        // Always enforce companyid from session, ignore any companyid in tool input.
        $companyid = $this->companyid;

        return match ($name) {
            'get_company_info'        => $this->get_company_info($companyid),
            'get_students_in_company' => $this->get_students_in_company($companyid),
            'get_quiz_performance'    => $this->get_quiz_performance($companyid),
            'get_course_list'         => $this->get_course_list($companyid),
            'get_student_quiz_detail' => $this->get_student_quiz_detail(
                $companyid,
                isset($input['userid']) ? (int)$input['userid'] : null
            ),
            default => ['error' => "Unknown tool: $name"],
        };
    }

    private function get_company_info(int $companyid): array {
        global $DB;

        $company = $DB->get_record('company', ['id' => $companyid], 'id,name,shortname,city,country');
        if (!$company) {
            return ['error' => 'Company not found'];
        }

        $student_count = $DB->count_records('company_users', ['companyid' => $companyid]);
        $course_count  = $DB->count_records('company_course', ['companyid' => $companyid]);

        return [
            'name'          => $company->name,
            'shortname'     => $company->shortname,
            'city'          => $company->city,
            'country'       => $company->country,
            'student_count' => $student_count,
            'course_count'  => $course_count,
        ];
    }

    private function get_students_in_company(int $companyid): array {
        global $DB;

        $sql = "SELECT u.id, u.firstname, u.lastname, u.email
                  FROM {user} u
                  JOIN {company_users} cu ON cu.userid = u.id
                 WHERE cu.companyid = :companyid
                   AND u.deleted = 0
                   AND u.suspended = 0
              ORDER BY u.lastname, u.firstname";

        $rows = $DB->get_records_sql($sql, ['companyid' => $companyid]);

        return array_values(array_map(fn($r) => [
            'id'        => (int)$r->id,
            'firstname' => $r->firstname,
            'lastname'  => $r->lastname,
            'email'     => $r->email,
        ], $rows));
    }

    private function get_quiz_performance(int $companyid): array {
        global $DB;

        $sql = "SELECT q.id, q.name, c.fullname AS course_name,
                       COUNT(qa.id) AS total_attempts,
                       SUM(CASE WHEN qa.sumgrades IS NOT NULL
                                 AND q.grade > 0
                                 AND (qa.sumgrades / q.grade) >= 0.5
                           THEN 1 ELSE 0 END) AS passes,
                       AVG(CASE WHEN q.grade > 0
                           THEN (qa.sumgrades / q.grade) * 100
                           ELSE NULL END) AS avg_score_pct
                  FROM {quiz} q
                  JOIN {course} c ON c.id = q.course
                  JOIN {company_course} cc ON cc.courseid = q.course AND cc.companyid = :companyid
             LEFT JOIN {quiz_attempts} qa ON qa.quiz = q.id AND qa.state = 'finished'
              GROUP BY q.id, q.name, c.fullname
              ORDER BY c.fullname, q.name";

        $rows = $DB->get_records_sql($sql, ['companyid' => $companyid]);

        return array_values(array_map(fn($r) => [
            'quiz_name'      => $r->name,
            'course_name'    => $r->course_name,
            'total_attempts' => (int)$r->total_attempts,
            'passes'         => (int)$r->passes,
            'pass_rate_pct'  => $r->total_attempts > 0
                ? round(($r->passes / $r->total_attempts) * 100, 1)
                : null,
            'avg_score_pct'  => $r->avg_score_pct !== null
                ? round((float)$r->avg_score_pct, 1)
                : null,
        ], $rows));
    }

    private function get_course_list(int $companyid): array {
        global $DB;

        $sql = "SELECT c.id, c.fullname, c.shortname,
                       COUNT(DISTINCT ue.userid) AS enrolled_students
                  FROM {course} c
                  JOIN {company_course} cc ON cc.courseid = c.id AND cc.companyid = :companyid
             LEFT JOIN {enrol} e ON e.courseid = c.id AND e.enrol = 'manual'
             LEFT JOIN {user_enrolments} ue ON ue.enrolid = e.id
              GROUP BY c.id, c.fullname, c.shortname
              ORDER BY c.fullname";

        $rows = $DB->get_records_sql($sql, ['companyid' => $companyid]);

        return array_values(array_map(fn($r) => [
            'id'               => (int)$r->id,
            'name'             => $r->fullname,
            'shortname'        => $r->shortname,
            'enrolled_students' => (int)$r->enrolled_students,
        ], $rows));
    }

    private function get_student_quiz_detail(int $companyid, ?int $userid = null): array {
        global $DB;

        $params = ['companyid' => $companyid];
        $user_filter = '';
        if ($userid !== null) {
            $user_filter = 'AND u.id = :userid';
            $params['userid'] = $userid;
        }

        $sql = "SELECT u.id AS userid,
                       u.firstname, u.lastname,
                       q.name AS quiz_name,
                       c.fullname AS course_name,
                       qa.sumgrades,
                       q.grade AS max_grade,
                       CASE WHEN q.grade > 0
                            THEN ROUND((qa.sumgrades / q.grade) * 100, 1)
                            ELSE NULL END AS score_pct,
                       qa.state,
                       FROM_UNIXTIME(qa.timefinish) AS finished_at
                  FROM {user} u
                  JOIN {company_users} cu ON cu.userid = u.id AND cu.companyid = :companyid
                  JOIN {quiz_attempts} qa ON qa.userid = u.id AND qa.state = 'finished'
                  JOIN {quiz} q ON q.id = qa.quiz
                  JOIN {course} c ON c.id = q.course
                  JOIN {company_course} cc ON cc.courseid = c.id AND cc.companyid = cu.companyid
                 WHERE u.deleted = 0 $user_filter
              ORDER BY u.lastname, u.firstname, c.fullname, q.name";

        $rows = $DB->get_records_sql($sql, $params);

        return array_values(array_map(fn($r) => [
            'student'     => "$r->firstname $r->lastname",
            'course'      => $r->course_name,
            'quiz'        => $r->quiz_name,
            'score'       => $r->sumgrades !== null ? (float)$r->sumgrades : null,
            'max_score'   => (float)$r->max_grade,
            'score_pct'   => $r->score_pct !== null ? (float)$r->score_pct : null,
            'finished_at' => $r->finished_at,
        ], $rows));
    }

    public static function tool_definitions(): array {
        return [
            [
                'name'        => 'get_company_info',
                'description' => 'Get general information about the school/company: name, city, total student count, and total course count.',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => new \stdClass(),
                    'required'   => [],
                ],
            ],
            [
                'name'        => 'get_students_in_company',
                'description' => 'List all students enrolled in this school/company with their names and emails.',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => new \stdClass(),
                    'required'   => [],
                ],
            ],
            [
                'name'        => 'get_quiz_performance',
                'description' => 'Get quiz performance statistics for this school: per-quiz attempt counts, pass counts, pass rate, and average score percentage.',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => new \stdClass(),
                    'required'   => [],
                ],
            ],
            [
                'name'        => 'get_course_list',
                'description' => 'List all courses assigned to this school with enrolment counts.',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => new \stdClass(),
                    'required'   => [],
                ],
            ],
            [
                'name'        => 'get_student_quiz_detail',
                'description' => 'Get individual quiz scores for all students (or one specific student) in this school. Includes score, max score, percentage, and pass/fail.',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'userid' => [
                            'type'        => 'integer',
                            'description' => 'Optional. Filter to a specific student by their Moodle user ID.',
                        ],
                    ],
                    'required'   => [],
                ],
            ],
        ];
    }
}
