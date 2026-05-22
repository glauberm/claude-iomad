<?php
defined('MOODLE_INTERNAL') || die();

class block_iomad_claude extends block_base {

    public function init(): void {
        $this->title = get_string('pluginname', 'block_iomad_claude');
    }

    public function applicable_formats(): array {
        return ['my' => true, 'site' => true];
    }

    public function has_config(): bool {
        return true;
    }

    public function get_content(): stdClass {
        global $OUTPUT, $USER;

        if ($this->content !== null) {
            return $this->content;
        }

        $this->content = new stdClass();
        $this->content->footer = '';

        $context = context_block::instance($this->instance->id);
        if (!isloggedin() || isguestuser()) {
            $this->content->text = '';
            return $this->content;
        }

        $templatecontext = [
            'blockid' => $this->instance->id,
            'userid'  => $USER->id,
        ];

        $this->content->text = $OUTPUT->render_from_template(
            'block_iomad_claude/widget',
            $templatecontext
        );

        return $this->content;
    }
}
