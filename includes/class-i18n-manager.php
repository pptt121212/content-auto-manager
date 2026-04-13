<?php
/**
 * I18n Manager Utility for Yali AI Writer
 * 
 * This script helps manage modular JSON translations and converts them to PO format.
 */

class Yali_AI_Writer_I18nManager {
    private $plugin_dir;
    private $languages_dir;
    private $text_domain = 'yali-ai-writer';

    public function __construct($plugin_dir) {
        $this->plugin_dir = rtrim($plugin_dir, '/');
        $this->languages_dir = $this->plugin_dir . '/languages';
    }

    /**
     * Merge modular JSON files into a temporary PO-style data structure.
     */
    public function merge_json_to_strings() {
        $source_dir = $this->languages_dir . '/source';
        if (!is_dir($source_dir)) {
            return [];
        }

        $all_strings = [];
        $files = glob($source_dir . '/*.json');
        foreach ($files as $file) {
            $content = file_get_contents($file);
            $data = json_decode($content, true);
            if (is_array($data)) {
                $all_strings = array_merge($all_strings, $data);
            }
        }
        return $all_strings;
    }

    /**
     * Generate a .po file content from the merged strings.
     * Note: This is a simplified version for AI to manage.
     */
    public function generate_po_content($lang = 'en_US') {
        $strings = $this->merge_json_to_strings();
        $header = <<<EOD
msgid ""
msgstr ""
"Project-Id-Version: Yali AI Writer\\n"
"Report-Msgid-Bugs-To: \\n"
"POT-Creation-Date: 2024-02-11 00:00+0000\\n"
"PO-Revision-Date: \\n"
"Last-Translator: \\n"
"Language-Team: \\n"
"Language: {$lang}\\n"
"MIME-Version: 1.0\\n"
"Content-Type: text/plain; charset=UTF-8\\n"
"Content-Transfer-Encoding: 8bit\\n"
"X-Generator: Yali i18n Manager\\n"
"X-Domain: {$this->text_domain}\\n"

EOD;

        $content = $header;
        foreach ($strings as $original => $translated) {
            $original_escaped = addcslashes($original, '"');
            $translated_escaped = addcslashes($translated, '"');
            $content .= "\nmsgid \"{$original_escaped}\"\nmsgstr \"{$translated_escaped}\"\n";
        }

        return $content;
    }
}
