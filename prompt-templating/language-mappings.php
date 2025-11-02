<?php
/**
 * 语言映射配置文件
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * 获取语言指令映射
 */
function content_auto_get_language_instructions($language_code = 'zh-CN') {
    $language_instructions = array(
        'zh-CN' => '请使用中文（简体）进行回复，采用适合中国大陆用户的表达方式和文化语境。',
        'zh-TW' => '請使用繁體中文進行回復，採用適合台灣用戶的表達方式和文化語境。',
        'en-US' => 'Please respond in American English, using expressions and cultural contexts suitable for users in the United States.',
        'en-GB' => 'Please respond in British English, using expressions and cultural contexts suitable for users in the United Kingdom.',
        'ja-JP' => '日本語で回答してください。日本のユーザーに適した表現方法と文化的文脈を使用してください。',
        'ko-KR' => '한국어로 답변해 주세요. 한국 사용자에게 적합한 표현 방식과 문화적 맥락을 사용해 주세요.',
        'fr-FR' => 'Veuillez répondre en français, en utilisant des expressions et des contextes culturels adaptés aux utilisateurs francophones.',
        'de-DE' => 'Bitte antworten Sie auf Deutsch und verwenden Sie Ausdrücke und kulturelle Kontexte, die für deutsche Benutzer geeignet sind.',
        'es-ES' => 'Por favor, responda en español, utilizando expresiones y contextos culturales adecuados para usuarios de habla hispana.',
        'pt-BR' => 'Por favor, responda em português brasileiro, usando expressões e contextos culturais adequados para usuários do Brasil.',
        'ru-RU' => 'Пожалуйста, отвечайте на русском языке, используя выражения и культурные контексты, подходящие для российских пользователей.',
        'ar-SA' => 'يرجى الرد باللغة العربية، باستخدام التعابير والسياقات الثقافية المناسبة للمستخدمين العرب.',
        'hi-IN' => 'कृपया हिंदी में उत्तर दें, भारतीय उपयोगकर्ताओं के लिए उपयुक्त अभिव्यक्तियों और सांस्कृतिक संदर्भों का उपयोग करें।',
        'th-TH' => 'กรุณาตอบเป็นภาษาไทย โดยใช้การแสดงออกและบริบททางวัฒนธรรมที่เหมาะสมสำหรับผู้ใช้ไทย',
        'vi-VN' => 'Vui lòng trả lời bằng tiếng Việt, sử dụng cách diễn đạt và bối cảnh văn hóa phù hợp với người dùng Việt Nam.',
        'id-ID' => 'Silakan menjawab dalam bahasa Indonesia, menggunakan ekspresi dan konteks budaya yang sesuai untuk pengguna Indonesia.'
    );
    
    return isset($language_instructions[$language_code]) ? $language_instructions[$language_code] : $language_instructions['zh-CN'];
}

/**
 * 获取语言名称映射（用于界面显示）
 */
function content_auto_get_language_names() {
    return array(
        'zh-CN' => '中文（简体）',
        'zh-TW' => '中文（繁体）',
        'en-US' => '英语（美式）',
        'en-GB' => '英语（英式）',
        'ja-JP' => '日语',
        'ko-KR' => '韩语',
        'fr-FR' => '法语',
        'de-DE' => '德语',
        'es-ES' => '西班牙语',
        'pt-BR' => '葡萄牙语（巴西）',
        'ru-RU' => '俄语',
        'ar-SA' => '阿拉伯语',
        'hi-IN' => '印地语',
        'th-TH' => '泰语',
        'vi-VN' => '越南语',
        'id-ID' => '印尼语'
    );
}

/**
 * 获取语言的AI识别名称（用于提示词约束）
 */
function content_auto_get_language_ai_names() {
    return array(
        'zh-CN' => '中文',
        'zh-TW' => '繁体中文',
        'en-US' => '英语',
        'en-GB' => '英语',
        'ja-JP' => '日语',
        'ko-KR' => '韩语',
        'fr-FR' => '法语',
        'de-DE' => '德语',
        'es-ES' => '西班牙语',
        'pt-BR' => '葡萄牙语',
        'ru-RU' => '俄语',
        'ar-SA' => '阿拉伯语',
        'hi-IN' => '印地语',
        'th-TH' => '泰语',
        'vi-VN' => '越南语',
        'id-ID' => '印尼语'
    );
}

/**
 * 获取语言的AI识别名称
 */
function content_auto_get_language_ai_name($language_code) {
    $ai_names = content_auto_get_language_ai_names();
    return isset($ai_names[$language_code]) ? $ai_names[$language_code] : $ai_names['zh-CN'];
}

/**
 * 验证语言代码是否有效
 */
function content_auto_validate_language_code($language_code) {
    $valid_languages = array_keys(content_auto_get_language_names());
    return in_array($language_code, $valid_languages) ? $language_code : 'zh-CN';
}