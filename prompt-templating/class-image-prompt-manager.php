<?php
/**
 * 图片生成的提示词模板管理器
 * 
 * 集中管理不同配图模式下的系统级提示词模板
 */

if (!defined('ABSPATH')) {
    exit;
}

class Yali_AI_Writer_ImagePromptManager {
    
    /**
     * 获取默认的配图提示词模板
     * 
     * 主要在UI无配置时提供默认的高级商务风占位符模板。
     * 
     * @return string
     */
    public static function get_default_template() {
        return '<image_generation_instructions>
    <instruction>
      Insert image generation prompts at appropriate positions in the article to guide the image AI in generating illustrations related to the content.
      All image prompts must be enclosed in HTML comment format as follows:
      
      <!-- image prompt: {English description of the image} -->
    </instruction>
    
    <image_placement_rules>
      <rule>Insert an image after the first or second paragraph at the beginning of the article</rule>
      <rule>Avoid inserting in the middle of lists, code blocks, or data tables</rule>
      <rule>Images should be surrounded by complete paragraphs to maintain reading flow</rule>
    </image_placement_rules>
    
    <image_prompt_guidelines>
      <style_requirements>
        <style>Minimalism</style>
        <style>Professional Business</style>
        <style>Visual Clarity</style>
      </style_requirements>
      
      <complexity_requirements>
        <complexity>Simple geometric shapes</complexity>
        <complexity>Abstract concept diagrams</complexity>
        <complexity>Icon style</complexity>
      </complexity_requirements>
      
      <color_scheme_guidelines>
        <guideline>Use soft color palettes</guideline>
        <guideline>Avoid overly vibrant or neon colors</guideline>
      </color_scheme_guidelines>
      
      <context_based_templates>
        <!-- Choose an appropriate image style based on content context -->
        <template context="Data-related">
          "Minimalist business dashboard with abstract data visualization, soft gradient background, professional color scheme, clean geometric shapes, flat design style"
        </template>
        
        <template context="Process-related">
          "Simple flowchart illustration with connected circles and arrows, light blue and white color scheme, clean minimal design, business presentation style"
        </template>
        
        <template context="Concept-related">
          "Abstract geometric composition with overlapping shapes, soft pastel colors, modern minimal style, professional business concept illustration"
        </template>
        
        <template context="Solution-related">
          "Simple puzzle pieces connecting together, gradient blue background, clean flat design, business solution concept, minimalist style"
        </template>
        
        <template context="Growth-related">
          "Upward trending graph with simple geometric elements, green and blue color palette, clean business infographic style, minimal design"
        </template>
        
        <template context="Network-related">
          "Abstract network nodes connected by lines, soft color scheme, clean minimal illustration, modern digital concept"
        </template>
        
        <template context="Collaboration-related">
          "Simple human figures in circular arrangement, soft colors, flat design icons, teamwork concept illustration, minimalist style"
        </template>
        
        <template context="Default">
          "Abstract business concept with geometric shapes, professional blue gradient, clean minimal design, modern corporate illustration"
        </template>
      </context_based_templates>
      
      <prompt_creation_rules>
        <rule>Maintain a length of 15-50 English words; complex scenes can be slightly longer</rule>
        <rule>Avoid describing specific people, brands, or realistic scenes</rule>
        <rule>Use universal visual elements (shapes, lines, gradients)</rule>
        <rule>Include style keywords: minimal, clean, professional, abstract</rule>
        <rule>Specify color preferences without strictly limiting to one color</rule>
        <rule>MANDATORY: The final prompt inside the HTML comment MUST be in English ONLY.</rule>
      </prompt_creation_rules>
    </image_prompt_guidelines>
    
    <context_analysis_instructions>
      <instruction>
        Select or adjust the prompt template based on the context of the image insertion point:
        - If discussing data, metrics, analysis -> Use Data-related style
        - If discussing processes, steps, methods -> Use Process-related style
        - If discussing ideas, thoughts, viewpoints -> Use Concept-related style
        - If discussing problems, challenges, solutions -> Use Solution-related style
        - If discussing results, benefits, growth -> Use Growth-related style
        - If discussing connections, relationships, structures -> Use Network-related style
        - If discussing teams, cooperation, common goals -> Use Collaboration-related style
        - If unsure -> Use Default style
      </instruction>
    </context_analysis_instructions>
    
    <example_correct_usage>
      <example>
        <!-- image prompt: Simple flowchart illustration with connected circles and arrows, light blue and white color scheme, clean minimal design, business presentation style -->
      </example>
    </example_correct_usage>
    
    <additional_examples>
      <example>
        <!-- image prompt: Abstract geometric composition with overlapping shapes, soft pastel colors, modern minimal style, professional business concept illustration -->
      </example>
      
      <example>
        <!-- image prompt: Minimalist business dashboard with abstract data visualization, soft gradient background, professional color scheme, clean geometric shapes -->
      </example>
    </additional_examples>
  </image_generation_instructions>';
    }

    /**
     * 获取系统预设的不可编辑高级配图提示词模板
     * 
     * @param string $mode 'flowchart' | 'content_match' | 'text_overlay'
     * @return string XML 格式的提示词模板
     */
    public static function get_preset_template($mode) {
        switch ($mode) {
            case 'flowchart':
                return '<image_generation_instructions>
  <instruction>
    Act as a professional information architect and senior business chart designer. Insert image generation prompts at appropriate positions in the article to generate high-quality business flowcharts, system architecture diagrams, or conceptual topologies.
    All image prompts must be enclosed in HTML comment format as follows:
    <!-- image prompt: {English description of the image} -->
  </instruction>
  
  <image_placement_rules>
    <rule>Insert after paragraphs that explain complex processes, system architectures, step-by-step guides, or module hierarchical relationships</rule>
    <rule>Avoid inserting in purely transitional paragraphs or areas without structured data</rule>
    <rule>Images should be surrounded by complete paragraphs to maintain reading flow</rule>
  </image_placement_rules>
  
  <diagram_style_requirements>
    <requirement>Style Constraint: Modern highly aesthetic infographic illustration, corporate vector art, clean sharp lines, geometric precision</requirement>
    <requirement>Visual Elements: Abstract interconnected nodes, sleek glowing data lines, floating isometric layers, minimalist blocks</requirement>
    <requirement>Background & Composition: Solid ultra-clean background (pure white or extremely soft muted gray), symmetrical or balanced grid layout</requirement>
    <requirement>Color Aesthetics: Premium corporate palette (e.g., deep cerulean blue with subtle neon cyan accents, or soft monochromatic slate gray with energetic amber highlights), high contrast, elegant flat colors</requirement>
  </diagram_style_requirements>
  
  <prompt_creation_rules>
    <rule>The prompt MUST be entirely in English, using optimized syntax for image generation models (phrase-based, separated by commas)</rule>
    <rule>Describe the abstract logical structure of the chart (e.g., "three-tier circular diagram", "layered isometric network", "sequential chevron process") rather than specific text</rule>
    <rule>STRICTLY PROHIBITED: Do not request specific text labels in the prompt (e.g., do not write "contains text XYZ") to avoid spelling errors. Focus on describing geometry and connections</rule>
    <rule>Always append reinforcement tags at the end: "vector graphics, sharp UI/UX asset style, minimalist corporate design, hyper-detailed cleanly rendered"</rule>
  </prompt_creation_rules>

  <example_correct_usage>
    <example><!-- image prompt: Clean isometric flowchart vector illustration showing a 3-step evolutionary process, sleek translucent glassmorphism cubes connected by glowing blue energy lines, floating over a pure white background, premium corporate UI asset style, deep cerulean and cyan color palette, sharp geometry, minimalist, ultra-detailed --></example>
    <example><!-- image prompt: Abstract circular network topology diagram, central glowing core radiating outward to secondary nodes, minimalist UI vector art, soft minimal slate background, amber and crisp white geometric accents, flat design, symmetrical balance, professional business infographic style --></example>
  </example_correct_usage>
</image_generation_instructions>';
                
            case 'content_match':
                return '<image_generation_instructions>
  <instruction>
    Act as a senior visual content director and Prompt engineer. Insert image generation prompts at appropriate positions in the article to guide the image AI in generating high-end illustrations or photography that closely matches the core business scene of the current paragraph and carries a strong sense of atmosphere.
    All image prompts must be enclosed in HTML comment format as follows:
    <!-- image prompt: {English description of the image} -->
  </instruction>
  
  <image_placement_rules>
    <rule>Insert after paragraphs that express core pain points, depict future visions, demonstrate specific usage scenarios, or have strong emotional/visual resonance</rule>
    <rule>Image quantity should be focused on quality; each insertion must distill the core "visual metaphor" of the paragraph</rule>
    <rule>Images should be surrounded by complete paragraphs to maintain layout breathing space</rule>
  </image_placement_rules>
  
  <contextual_illustration_guidelines>
    <style_directives>
      <directive>Tech/B2B/Data: Use "Sleek modern 3D render, minimalist tech aesthetic, glassmorphism elements, cinematic studio lighting"</directive>
      <directive>Life/Education/Service: Use "High-end editorial photography, warm golden hour lighting, cinematic rim light, shallow depth of field (85mm lens), authentic lifestyle candid"</directive>
      <directive>Abstract/Concept: Use "Premium flat vector illustration, editorial art style, sophisticated pastel gradients, minimalist composition"</directive>
    </style_directives>
    
    <content_directives>
      <directive>Translate abstract concepts into concrete visual metaphors (e.g., "breaking barriers" -> "A glowing bridge connecting two separated floating cliffs"; "data insight" -> "A luminous magnifying glass glowing over highly technical abstract geometric patterns")</directive>
      <directive>For photography style, use compositional terms (e.g., "over-the-shoulder shot", "wide establishing shot") and lighting terms (e.g., "soft volumetric lighting", "chiaroscuro")</directive>
      <directive>Character strategies: Prioritize atmospheric details (e.g., close-up of hands typing, hand pointing at a screen), silhouettes, or back views. If faces must be shown, add "highly attractive, natural expressive aesthetic, Vogue editorial style" to avoid uncanny AI faces</directive>
    </content_directives>
  </contextual_illustration_guidelines>
  
  <prompt_creation_rules>
    <rule>The prompt MUST be entirely in English, following the structure: [Main Core Scene] + [Environment/Background] + [Shot/Composition Angle] + [Light & Shadow / Color] + [Medium Style / Render Engine Quality Words]</rule>
    <rule>Use commas to separate and stack high-fidelity modifiers (e.g., "unreal engine 5 render, highly detailed, sharp focus")</rule>
  </prompt_creation_rules>
  
  <example_correct_usage>
    <example><!-- image prompt: Cinematic photography of a professional diverse team analyzing glowing holographic data charts hovering above a sleek modern conference table, over-the-shoulder shot, shallow depth of field, 85mm lens, cinematic studio lighting with deep blue and warm amber tones, high-end corporate lifestyle, highly detailed, sharp focus --></example>
    <example><!-- image prompt: Sophisticated modern vector illustration of a massive glowing key unlocking a floating mechanical vault, representing security solutions, clean pastel gradient background, editorial art style, sleek curves, dramatic lighting, premium corporate aesthetics --></example>
  </example_correct_usage>
</image_generation_instructions>';
                
            case 'text_overlay':
                return '<image_generation_instructions>
  <instruction>
    Act as an internationally renowned visual art director and top-tier poster typography master. Your task is to insert image generation prompts at key points in the article to create "visual gold sentences" or "art posters" that highly integrate aesthetic value, artistic sense, and textual depth.
    All image prompts must be enclosed in HTML comment format as follows:
    <!-- image prompt: {English description of the image} -->
  </instruction>
  
  <image_placement_rules>
    <rule>Hero Image: Insert below the main article title to set the visual tone for the entire piece</rule>
    <rule>Turning Point/Key Quote: Insert at the beginning of core sections or key point summaries to set the visual mood</rule>
    <rule>Layout Spacing: Ensure images are surrounded by complete paragraphs to create reading breathing space</rule>
  </image_placement_rules>
  
  <typography_art_guidelines>
    <context_aware_composition>
      <rule>Background Adaptation: Dynamically choose backgrounds based on the article theme.
        - Tech/Business/Tutorial: Use "theme-related infographic" backgrounds (e.g., macro electronic components, blurred office scenes, abstract data flows, modern gadgets).
        - Emotional/Humanities/Life/Blog: Use "stylized mood" backgrounds (e.g., Morandi color gradients, minimalist geometric lines, breathing white space).
        - Social Media/Portal Hero Images: Aim for high saturation or strong visual contrast to ensure text is the primary focus while the background conveys industry warmth.</rule>
      <rule>Text Extraction: Extract core keywords or short phrases with actual information content (recommended 4-15 characters). The text should serve as a "visual sub-point" for that section.</rule>
      <rule>Language Consistency: The text MUST use the target language of the article ({{LANGUAGE_NAME}}). Do not translate it. It must be enclosed in double quotes.</rule>
    </context_aware_composition>
    
    <style_presets_and_inspirations>
        <style name="Modern Tech Blog">Keywords: Sleek futuristic UI elements, soft glowing circuit patterns, macro photography of high-tech devices, blurred glassmorphism overlays, professional tech-blue or cyber-green color palette, clean modern typography.</style>
        <style name="Editorial Showcase">Keywords: High-contrast poster style, bold avant-garde typography, vibrant gradients, stylized metaphorical graphics (e.g., a glowing key, a trending icon), trendy flat design aesthetics, focal point clarity.</style>
        <style name="Minimalist Zen">Keywords: Oriental ink wash, ethereal negative space, rice paper texture, minimalist balanced composition, zen-like calm, earthy tones, cinematic lighting.</style>
    </style_presets_and_inspirations>

    <visual_integrity_constraints>
      <constraint>Typography Purity: Avoid using generic quality tags like "8K", "4K", "HD", "Poster", "Design" in the prompt to prevent the AI from mistakenly rendering these words into the image.</constraint>
      <constraint>Typographic Structure: Text should interact with graphic elements in the background (e.g., text wrapping around an object, text as a depth boundary), avoiding a simple "sticker" feel.</constraint>
    </visual_integrity_constraints>
  </typography_art_guidelines>
  
  <prompt_creation_rules>
    <rule>The main body of the prompt MUST be in English. Text to be rendered must be enclosed in double quotes and follow the instruction text "".</rule>
    <rule>Structure Suggestion: [Text Instructions & Typography Description] + [Core Visual Subject/Metaphor] + [Environment Texture, Lighting & Composition] + [Art Style/Master Reference].</rule>
    <rule>Emphasize physical properties of text: e.g., "text as a floating neon sign", "text dissolved into brushstrokes", "text carved into ancient stone".</rule>
  </prompt_creation_rules>
  
  <example_correct_usage>
    <example><!-- image prompt: The text "Your Chinese Quote" written in bold floating calligraphy, reflecting on a misty lake surface at dusk, two tiny silhouettes of martial artists dueling in distance, cinematic wuxia aesthetic, soft volumetric lighting, oriental minimalism --></example>
    <example><!-- image prompt: The text "Future Tech" integrated into a sleek abstract conceptual art scene, floating geometric fragments with misty jade and silver gradients, ethereal mental space texture, soft-focus background, sophisticated balanced grid composition, modern meditative atmosphere --></example>
    <example><!-- image prompt: The text "BREAKTHROUGH NOW" in a bold avant-garde graffiti font, clashing vibrant neon colors, heavy textured overlays and paper grain, dynamic diagonal composition, urban contemporary art style --></example>
  </example_correct_usage>
</image_generation_instructions>';
                
            default:
                // Provide a safe fallback if mode is completely unknown
                return self::get_default_template();
        }
    }
}
