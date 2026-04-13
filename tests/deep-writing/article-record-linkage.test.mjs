import test from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'

const databasePath = resolve(process.cwd(), 'yali-ai-writer/shared/database/class-database.php')
const databaseSource = readFileSync(databasePath, 'utf8')
const bootstrapPath = resolve(process.cwd(), 'yali-ai-writer/yali-ai-writer.php')
const bootstrapSource = readFileSync(bootstrapPath, 'utf8')
const handlerPath = resolve(process.cwd(), 'yali-ai-writer/deep-writing/class-deep-writing-handler.php')
const handlerSource = readFileSync(handlerPath, 'utf8')

test('topics canonical schema does not define a deep-writing post_id linkage column', () => {
  const topicsSchema = databaseSource.match(/\$topics_table = .*?\) ' \./s)?.[0] ?? ''
  assert.doesNotMatch(topicsSchema, /`post_id` bigint\(20\)/)
})

test('database layer no longer carries a topics post_id migration path', () => {
  assert.doesNotMatch(databaseSource, /update_topics_table_for_post_id\(/)
  assert.doesNotMatch(bootstrapSource, /update_topics_table_for_post_id\(/)
})

test('deep-writing handler reads topic post linkage from article records', () => {
  assert.match(handlerSource, /SELECT post_id FROM \{\$articles_table\} WHERE topic_id = %d/)
  assert.doesNotMatch(handlerSource, /SELECT post_id FROM \{\$topics_table\} WHERE id = %d AND status = %s/)
})

test('deep-writing handler persists article records on successful draft sync', () => {
  assert.match(handlerSource, /INSERT INTO \{\$articles_table\}|\$wpdb->insert\(\s*\$articles_table/)
})
