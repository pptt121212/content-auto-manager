import test from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'

const handlerPath = resolve(process.cwd(), 'yali-ai-writer/deep-writing/class-deep-writing-handler.php')
const handlerSource = readFileSync(handlerPath, 'utf8')

test('featured image URL should reuse an existing local attachment before download', () => {
  const block = handlerSource.match(/private static function attach_featured_image\([\s\S]*?^    }/m)?.[0] ?? ''
  assert.match(block, /resolve_attachment_id_from_url\(\$featured_image\)/)
  assert.match(block, /set_post_thumbnail\(\$post_id, \$existing_attachment_id\)/)
})

test('draft creation should attach inline article images to the created post', () => {
  assert.match(handlerSource, /self::attach_inline_images_to_post\(\$post_id, \$html_content\)/)
  assert.match(handlerSource, /private static function attach_inline_images_to_post\(/)
  assert.match(handlerSource, /attachment_url_to_postid\(/)
  assert.match(handlerSource, /wp_update_post\(/)
})
