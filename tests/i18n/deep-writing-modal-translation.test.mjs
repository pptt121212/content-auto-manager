import test from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'

const buildScriptPath = resolve(process.cwd(), 'yali-ai-writer/languages/build-mo-v2.php')
const buildScriptSource = readFileSync(buildScriptPath, 'utf8')

const jedPath = resolve(process.cwd(), 'yali-ai-writer/languages/yali-ai-writer-en_US-yali-ai-writer-topics-list-inline-js.json')
const jed = JSON.parse(readFileSync(jedPath, 'utf8'))
const messages = jed.locale_data?.messages ?? {}

test('deep-writing modal copy is force-included for topics-list-inline translations', () => {
  assert.match(buildScriptSource, /'yali-ai-writer-topics-list-inline-js' => array\([\s\S]*'深度写作需要打开鸭梨AI浏览器扩展，每篇文章约执行5~30分钟'/)
  assert.match(buildScriptSource, /'yali-ai-writer-topics-list-inline-js' => array\([\s\S]*'确认写作'/)
})

test('topics-list-inline English JED contains deep-writing modal translations', () => {
  assert.equal(messages['深度写作需要打开鸭梨AI浏览器扩展，每篇文章约执行5~30分钟']?.[0], 'Deep Writing requires the Yali AI Browser Extension, and each article usually takes about 5 to 30 minutes to complete.')
  assert.equal(messages['在此期间不要关闭鸭梨AI浏览器扩展。']?.[0], 'Do not close the Yali AI Browser Extension during this time.')
  assert.equal(messages['完成后会将文章发布到文章列表，转为草稿，请校对没问题后发布。']?.[0], 'When finished, the article will be sent to the Articles list as a draft. Please review it carefully before publishing.')
  assert.equal(messages['文章中会自动配图，请提前配置好图像API。']?.[0], 'Images will be generated automatically for the article, so please configure the image API in advance.')
  assert.equal(messages['鸭梨AI浏览器扩展']?.[0], 'Yali AI Browser Extension')
  assert.equal(messages['确认写作']?.[0], 'Confirm Writing')
})
