import test from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'

const handlerPath = resolve(process.cwd(), 'yali-ai-writer/deep-writing/class-deep-writing-handler.php')
const handlerSource = readFileSync(handlerPath, 'utf8')

test('deep-writing callback loads file.php before download_url usage', () => {
  assert.match(
    handlerSource,
    /function_exists\s*\(\s*['"]download_url['"]\s*\).*wp-admin\/includes\/file\.php/s,
  )
})

test('featured image attachment stays non-blocking when media download throws', () => {
  const attachFeatureImageBlock = handlerSource.match(/private static function attach_featured_image\([\s\S]*?^    }/m)?.[0] ?? ''
  assert.match(attachFeatureImageBlock, /catch\s*\(\s*\\Throwable\s+\$\w+\s*\)/)
})
