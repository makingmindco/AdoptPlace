export function passwordStrength(password) {
  const rules = [
    { label: '10 caracteres ou mais', met: [...password].length >= 10 },
    { label: 'Uma letra maiúscula', met: /\p{Lu}/u.test(password) },
    { label: 'Uma letra minúscula', met: /\p{Ll}/u.test(password) },
    { label: 'Um número', met: /\p{N}/u.test(password) },
    { label: 'Um símbolo, como ! ou @', met: /[\p{Z}\p{S}\p{P}]/u.test(password) },
  ]
  const valid = rules.every(rule => rule.met)
  const score = rules.filter(rule => rule.met).length + (valid && [...password].length >= 14 ? 1 : 0)
  return { rules, valid, score, label: !password ? 'Comece a digitar' : score === 6 ? 'Forte' : valid ? 'Boa' : score >= 3 ? 'Pode melhorar' : 'Fraca' }
}
