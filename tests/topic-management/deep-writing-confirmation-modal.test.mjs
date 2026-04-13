import test from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'

const topicsListViewPath = resolve(process.cwd(), 'yali-ai-writer/topic-management/views/topics-list.php')
const topicsListJsPath = resolve(process.cwd(), 'yali-ai-writer/topic-management/assets/js/topics-list-inline.js')

const topicsListViewSource = readFileSync(topicsListViewPath, 'utf8')
const topicsListJsSource = readFileSync(topicsListJsPath, 'utf8')

test('topics page renders a deep-writing confirmation modal with extension hyperlink', () => {
  assert.match(topicsListViewSource, /id="deep-writing-confirm-modal"/)
  assert.match(topicsListViewSource, /https:\/\/www\.yaliai\.com\/product\/extension\//)
  assert.match(topicsListViewSource, /鸭梨AI浏览器扩展/)
  assert.match(topicsListViewSource, /确认写作/)
})

test('topics list JS intercepts deep-writing clicks and requires confirmation before submit', () => {
  assert.match(topicsListJsSource, /input\[name="deep_writing"\]/)
  assert.match(topicsListJsSource, /preventDefault\(\)/)
  assert.match(topicsListJsSource, /deep-writing-confirm-submit/)
  assert.match(topicsListJsSource, /\$deepWritingForm\[0\]\.submit\(\)/)
})
