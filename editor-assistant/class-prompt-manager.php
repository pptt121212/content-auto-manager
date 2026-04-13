<?php
/**
 * 提示词管理类
 */

if (!defined('ABSPATH')) {
    exit;
}

class Yali_AI_Writer_Editor_Prompt_Manager {

    /**
     * 获取默认提示词列表
     */
    public static function get_default_prompts() {
        return array(
            array(
                'prompt_title'   => '写一段话',
                'prompt_content' => "Write a paragraph on this topic: [[text_1]]. Format in HTML, using only these allowed tags:  <p> <strong> ",
                'prompt_desc'    => '根据选中文本生成段落，使用不同的词汇表达相同的意思。',
                'word_count'     => 300,
            ),
            array(
                'prompt_title'   => '续写文本',
                'prompt_content' => "Continue this text: [[text_1]]. Format in HTML, using only these allowed tags: <p> <strong> <br>",
                'prompt_desc'    => '在当前文本的基础上继续撰写下一段内容。',
                'word_count'     => 300,
            ),
            array(
                'prompt_title'   => '生成创意',
                'prompt_content' => "Generate a few ideas on that as bullet points: [[text_1]] Format in HTML, using only these allowed tags:  <li> <ul> <p> <strong>",
                'prompt_desc'    => '解锁你的创造力，为项目头脑风暴或寻求灵感。',
                'word_count'     => 400,
            ),
            array(
                'prompt_title'   => '写一篇文章',
                'prompt_content' => "Write a complete article about this:[[text_1]].  Format in HTML, using only these allowed tags: <h2><h3> <h4> <li> <ul> <p>  <strong>",
                'prompt_desc'    => '优化你的博客文章以提升流量和参与度，撰写信息丰富且对搜索引擎友好的文章。',
                'word_count'     => 1500,
            ),
            array(
                'prompt_title'   => '生成摘要(TL;DR)',
                'prompt_content' => "Generate a TL;DR of this text: [[text_1]]. Format in HTML, using only these allowed tags:  <p> <br> <strong> <li> <ul>",
                'prompt_desc'    => '将长文本总结为简洁的片段。提供简要概述，以便快速掌握要点。',
                'word_count'     => 1500,
            ),
            array(
                'prompt_title'   => '总结',
                'prompt_content' => "Summarize this text: [[text_1]]. Format in HTML, using only these allowed tags:  <p> <strong> <li> <ul>",
                'prompt_desc'    => '简明扼要地总结文本，使其易于理解。',
                'word_count'     => 400,
            ),
            array(
                'prompt_title'   => '简洁总结',
                'prompt_content' => "Summarize this text in a concise way: [[text_1]]. Format in HTML, using only these allowed tags:  <p><strong> <li> <ul>",
                'prompt_desc'    => '将文本浓缩为简洁的摘要，捕捉其精髓。',
                'word_count'     => 400,
            ),
            array(
                'prompt_title'   => '要点总结',
                'prompt_content' => "Summarize this text into bullet points: [[text_1]]. Format in HTML, using only these allowed tags:   <li> <ul> <p> <strong>",
                'prompt_desc'    => '将给定文本总结为简洁的要点，以便更好地理解和快速参考。',
                'word_count'     => 400,
            ),
            array(
                'prompt_title'   => '改写',
                'prompt_content' => "Paraphrase this text: [[text_1]] . Format in HTML, using only these allowed tags:  <p> <strong> ",
                'prompt_desc'    => '使用不同的词汇表达相同的意思，同时保持原意。',
                'word_count'     => 400,
            ),
            array(
                'prompt_title'   => '讽刺改写',
                'prompt_content' => "Paraphrase this text in a sarcastic way: [[text_1]]. Format in HTML, using only these allowed tags:  <p> <strong> ",
                'prompt_desc'    => '以讽刺的口吻使用不同的词汇表达相同的意思。',
                'word_count'     => 400,
            ),
            array(
                'prompt_title'   => '幽默改写',
                'prompt_content' => "Paraphrase this text in a humorous way: [[text_1]].  Format in HTML, using only these allowed tags:  <p> <strong> <li> <ul>",
                'prompt_desc'    => '以幽默的口吻使用不同的词汇表达相同的意思。',
                'word_count'     => 400,
            ),
            array(
                'prompt_title'   => '生成小标题',
                'prompt_content' => "Generate a title for this text: [[text_1]] Format in HTML, using only these allowed tags:  <h2>",
                'prompt_desc'    => '自动为文本生成准确的小标题，使内容更加清晰易读。',
                'word_count'     => 400,
            ),
            array(
                'prompt_title'   => '转为广告文案',
                'prompt_content' => "Turn the following text into a creative advertisement: [[text_1]] Format in HTML, using only these allowed tags:  <p> <strong> <li> <ul>",
                'prompt_desc'    => '将你的广告提升到一个新的水平并取得成功。',
                'word_count'     => 400,
            ),
            array(
                'prompt_title'   => '向5岁孩子解释',
                'prompt_content' => "Explain this to a 5 years old kid: [[text_1]] . Format in HTML, using only these allowed tags:  <p>  <strong> <li> <ul>",
                'prompt_desc'    => '用简单的词汇向5岁孩子解释，就像讲故事一样帮助他们理解。',
                'word_count'     => 400,
            ),
            array(
                'prompt_title'   => '查找引言配图',
                'prompt_content' => "Find a matching quote for the following text: [[text_1]]. Format in HTML, using only these allowed tags:  <p> <strong> <li> <ul>",
                'prompt_desc'    => '发现与你的思想和情感产生共鸣的完美词句，为各种场合找到匹配的引言。',
                'word_count'     => 400,
            ),
            array(
                'prompt_title'   => '生成图片创意',
                'prompt_content' => "Describe an image that would match this text: [[text_1]]",
                'prompt_desc'    => '发现无尽的创意世界。解锁灵感并为你的下一个项目生成独特的视觉效果。',
                'word_count'     => 400,
            ),
            array(
                'prompt_title'   => '内容重写',
                'prompt_content' => "Content rewriter on this: [[text_1]]  Format in HTML, using only these allowed tags:  <p> <strong> <li> <ul>",
                'prompt_desc'    => '轻松转换你的书面材料，通过产生新鲜的变体来提高可读性和独特性。',
                'word_count'     => 400,
            ),
            array(
                'prompt_title'   => '写客户评价/测评',
                'prompt_content' => "Create 5 creative customer reviews for a product on this: [[text_1]] ,Format in HTML, using only these allowed tags:  <p> <strong> ",
                'prompt_desc'    => '深入了解引人入胜的评价和深刻的评论，激发并告知你的决策过程。',
                'word_count'     => 600,
            ),
            array(
                'prompt_title'   => '写博客标题',
                'prompt_content' => "Generate 10 catchy blog titles for: [[text_1]]. Format in HTML, using only these allowed tags:  <h1>",
                'prompt_desc'    => '释放语言的力量，打造一个引人注目的博客标题，吸引读者的注意力。',
                'word_count'     => 400,
            ),
            array(
                'prompt_title'   => '写常见问题(FAQ)',
                'prompt_content' => "Generate list of 10 frequently asked questions based on: [[text_1]] Format in HTML, using only these allowed tags: <h3> <li> <ul> <p> <strong>",
                'prompt_desc'    => '精心制作全面的常见问题，解决普遍疑问，为用户提供清晰简洁的答案。',
                'word_count'     => 600,
            ),
            array(
                'prompt_title'   => '写FAQ回答',
                'prompt_content' => "Generate creative 5 answers to question: [[text_1]]. Format in HTML, using only these allowed tags: <h3> <li> <ul> <p> <strong> ",
                'prompt_desc'    => '精心制作简洁且信息丰富的常见问题解答，以解决普遍疑问并增强用户理解。',
                'word_count'     => 600,
            ),
        );
    }

    /**
     * 获取支持的语言列表
     */
    public static function get_supported_languages() {
        return array(
            'en' => __('英文 (English)', 'yali-ai-writer'),
            'zh' => __('中文 (Chinese)', 'yali-ai-writer'),
            'ja' => __('日语 (Japanese)', 'yali-ai-writer'),
            'ko' => __('韩语 (Korean)', 'yali-ai-writer'),
            'es' => __('西班牙语 (Spanish)', 'yali-ai-writer'),
            'fr' => __('法语 (French)', 'yali-ai-writer'),
            'de' => __('德语 (German)', 'yali-ai-writer'),
            'ru' => __('俄语 (Russian)', 'yali-ai-writer'),
            'pt' => __('葡萄牙语 (Portuguese)', 'yali-ai-writer'),
            'it' => __('意大利语 (Italian)', 'yali-ai-writer')
        );
    }

    /**
     * 获取提示词（分组格式）
     * 结构：[ { group_language, prompt_language, new_prompt: [...] }, ... ]
     */
    public static function get_prompts() {
        $saved_prompts = get_option('yali_editor_assistant_prompts', array());
        $supported_languages = self::get_supported_languages();
        
        $locale = get_user_locale();
        $current_lang = (stripos($locale, 'zh') === 0) ? 'zh' : 'en';

        // 获取当前界面语言对应的提示词（优先从数据库加载，没有则用默认）
        if (isset($saved_prompts[$current_lang]) && !empty($saved_prompts[$current_lang])) {
            $prompts_array = $saved_prompts[$current_lang];
        } else {
            // 数据库没有该语言配置时，加载默认提示词（默认目前采用原始中文作为 key）
            $prompts_array = self::get_default_prompts();
        }

        // 执行运行时翻译：
        // 1. 如果当前界面是中文，__('写一段话') -> 返回 '写一段话'（无 MO 时默认返回原串）
        // 2. 如果当前界面是英文，__('写一段话') -> 返回 'Write a paragraph on this'（由 en_US.mo 提供）
        foreach ($prompts_array as &$prompt) {
            if (isset($prompt['prompt_title'])) {
                $prompt['prompt_title'] = __($prompt['prompt_title'], 'yali-ai-writer');
            }
            if (isset($prompt['prompt_desc'])) {
                $prompt['prompt_desc'] = __($prompt['prompt_desc'], 'yali-ai-writer');
            }
        }
        unset($prompt);

        // 从JSON配置文件加载图像生成提示词并合并
        if (class_exists('Yali_AI_Writer_Image_Prompts_Loader')) {
            $image_prompts = Yali_AI_Writer_Image_Prompts_Loader::load_image_prompts();
            if (!empty($image_prompts)) {
                $prompts_array = array_merge($prompts_array, $image_prompts);
            }
        }

        // 仅返回符合当前界面语言的一个分组，杜绝“中英双重菜单”或“英文双重菜单”导致的重复和混淆
        return array(
            array(
                'group_language'  => isset($supported_languages[$current_lang]) ? $supported_languages[$current_lang] : $current_lang,
                'prompt_language' => $current_lang,
                'new_prompt'      => $prompts_array,
            )
        );
    }

    /**
     * 获取展平的提示词列表（用于 REST API 按索引查找）
     */
    public static function get_flat_prompts() {
        $flat = array();
        foreach (self::get_prompts() as $group) {
            foreach ($group['new_prompt'] as $prompt) {
                // 原版逻辑中，前端其实只传了 index 和 prompt 内容本身
                // 但原版 API 是从 flat 中根据 index 获取的。如果有多语言，index 会乱！
                // 暂时保持原样，将所有合并。
                $flat[] = $prompt;
            }
        }
        return $flat;
    }

    /**
     * 根据索引获取单个提示词（在展平列表中查找）
     */
    public static function get_prompt_by_lang_index($lang, $index) {
        $all_groups = self::get_prompts();
        foreach ($all_groups as $group) {
            if ($group['prompt_language'] === $lang) {
                if (isset($group['new_prompt'][$index])) {
                    return $group['new_prompt'][$index];
                }
            }
        }
        
        // 如果找不到指定语言的，降级尝试展平列表 (兼容原来可能出错的调用)
        return self::get_prompt_by_index($index);
    }

    public static function get_prompt_by_index($index) {
        $prompts = self::get_flat_prompts();
        return $prompts[$index] ?? null;
    }
}