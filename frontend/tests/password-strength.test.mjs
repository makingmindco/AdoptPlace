import test from 'node:test'
import assert from 'node:assert/strict'
import { passwordStrength } from '../src/utils/passwordStrength.js'

test('password requirements agree with registration policy', () => {
  for (const value of ['', '1234567890', 'abcdefghij', 'Abcdefghij', 'Abcdefg123', 'Ab1!']) {
    assert.equal(passwordStrength(value).valid, false, value)
  }
  assert.equal(passwordStrength('Encontro#123').valid, true)
  assert.equal(passwordStrength('Amigo#12').valid, true)
  assert.equal(passwordStrength('Amigo#1').valid, false)
  assert.equal(passwordStrength('Encontro#123').label, 'Boa')
  assert.equal(passwordStrength('MeuEncontro#12345').label, 'Forte')
  assert.equal(passwordStrength('AdoçãoFeliz#123').valid, true)
})
