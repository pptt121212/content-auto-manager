import test from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'

const handlerPath = resolve(process.cwd(), 'yali-ai-writer/deep-writing/class-deep-writing-handler.php')
const handlerSource = readFileSync(handlerPath, 'utf8')

test('deep-writing handler loads the shared markdown conversion pipeline', () => {
  assert.match(handlerSource, /shared\/content-processing\/class-content-filter\.php/)
  assert.match(handlerSource, /shared\/content-processing\/class-markdown-converter\.php/)
})

test('deep-writing handler filters markdown and converts it to html before wp_insert_post', () => {
  assert.match(handlerSource, /filter_content\(\$content\)/)
  assert.match(handlerSource, /markdown_to_html\(\$filtered_content\)/)
  assert.match(handlerSource, /'post_content'\s*=>\s*\$html_content/)
})
