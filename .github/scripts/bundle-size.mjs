#!/usr/bin/env node
// Mede e compara o tamanho dos bundles JS da extensão (js/dist/**.js), raw e
// gzip. Espelha o fluxo do compare-lhci.mjs: na PR compara contra a baseline
// do main e gera um markdown com deltas 🟢/🔴 (report-only). O comando
// `budget` confere tetos absolutos por arquivo e falha quando um estoura.
//
// Uso:
//   node bundle-size.mjs measure <distDir> <outJson>
//   node bundle-size.mjs budget  <json> <budgetsJson>       (exit 1 se estourar)
//   node bundle-size.mjs compare <prJson> <baselineJson> [budgetsJson]   (> comparison.md)

import { readFileSync, writeFileSync, readdirSync, existsSync } from 'node:fs';
import { join } from 'node:path';
import { gzipSync } from 'node:zlib';

const [, , cmd, ...rest] = process.argv;

function walkJs(dir) {
  const out = [];
  if (!existsSync(dir)) return out;
  for (const ent of readdirSync(dir, { withFileTypes: true })) {
    const full = join(dir, ent.name);
    if (ent.isDirectory()) out.push(...walkJs(full));
    else if (ent.isFile() && ent.name.endsWith('.js')) out.push(full);
  }
  return out;
}

function kb(bytes) {
  return (bytes / 1024).toFixed(1) + ' KB';
}

function loadBudgets(path) {
  if (!path || !existsSync(path)) return null;
  const raw = JSON.parse(readFileSync(path, 'utf8'));
  const out = {};
  for (const [k, v] of Object.entries(raw)) {
    if (k.startsWith('_') || !v || typeof v !== 'object') continue;
    out[k] = v;
  }
  return out;
}

// Lista de violações { file, kind, size, limit } — arquivo orçado mas ausente
// no build também conta (o webpack deixou de gerar o bundle esperado).
function checkBudgets(data, budgets) {
  const violations = [];
  for (const [file, limits] of Object.entries(budgets)) {
    const got = data.files[file];
    if (!got) {
      violations.push({ file, kind: 'missing', size: 0, limit: 0 });
      continue;
    }
    for (const kind of ['raw', 'gzip']) {
      if (typeof limits[kind] === 'number' && got[kind] > limits[kind]) {
        violations.push({ file, kind, size: got[kind], limit: limits[kind] });
      }
    }
  }
  return violations;
}

function budgetLines(data, budgets) {
  const lines = ['### 🎯 Orçamento (`.github/bundle-budgets.json`)', '', '| Arquivo | raw / teto | gzip / teto | Status |', '|---|---:|---:|---|'];
  const violations = checkBudgets(data, budgets);
  for (const [file, limits] of Object.entries(budgets)) {
    const got = data.files[file];
    const bad = violations.some((v) => v.file === file);
    const rawCell = got ? `${kb(got.raw)} / ${kb(limits.raw ?? 0)}` : '—';
    const gzCell = got ? `${kb(got.gzip)} / ${kb(limits.gzip ?? 0)}` : '—';
    lines.push(`| \`${file}\` | ${rawCell} | ${gzCell} | ${got ? (bad ? '🔴 acima do teto' : '🟢 ok') : '🔴 ausente'} |`);
  }
  lines.push('');
  return { lines, violations };
}

// ── measure ────────────────────────────────────────────────────────────────
if (cmd === 'measure') {
  const [distDir, outJson] = rest;
  if (!distDir || !outJson) {
    console.error('Uso: bundle-size.mjs measure <distDir> <outJson>');
    process.exit(2);
  }
  const base = distDir.replace(/[\\/]+$/, '');
  const files = {};
  let totalRaw = 0;
  let totalGzip = 0;
  for (const f of walkJs(base).sort()) {
    const buf = readFileSync(f);
    const raw = buf.length;
    const gzip = gzipSync(buf, { level: 9 }).length;
    const rel = f.slice(base.length + 1).replace(/\\/g, '/');
    files[rel] = { raw, gzip };
    totalRaw += raw;
    totalGzip += gzip;
  }
  const data = { files, total: { raw: totalRaw, gzip: totalGzip } };
  writeFileSync(outJson, JSON.stringify(data, null, 2));
  process.stdout.write(JSON.stringify(data, null, 2) + '\n');
  process.exit(0);
}

// ── budget ─────────────────────────────────────────────────────────────────
if (cmd === 'budget') {
  const [jsonPath, budgetsPath] = rest;
  if (!jsonPath || !budgetsPath) {
    console.error('Uso: bundle-size.mjs budget <json> <budgetsJson>');
    process.exit(2);
  }
  const data = JSON.parse(readFileSync(jsonPath, 'utf8'));
  const budgets = loadBudgets(budgetsPath);
  if (!budgets) {
    console.error(`Arquivo de orçamento não encontrado: ${budgetsPath}`);
    process.exit(2);
  }
  const { lines, violations } = budgetLines(data, budgets);
  process.stdout.write(lines.join('\n') + '\n');
  for (const v of violations) {
    const msg = v.kind === 'missing'
      ? `${v.file} não foi gerado pelo build`
      : `${v.file} (${v.kind}) = ${v.size} B, teto ${v.limit} B`;
    process.stdout.write(`::error::Orçamento de bundle estourado — ${msg}\n`);
  }
  process.exit(violations.length ? 1 : 0);
}

// ── compare ────────────────────────────────────────────────────────────────
if (cmd === 'compare') {
  const [prPath, basePath, budgetsPath] = rest;
  if (!prPath) {
    console.error('Uso: bundle-size.mjs compare <prJson> <baselineJson> [budgetsJson]');
    process.exit(2);
  }
  const pr = JSON.parse(readFileSync(prPath, 'utf8'));
  const base = basePath && existsSync(basePath) ? JSON.parse(readFileSync(basePath, 'utf8')) : null;

  // 🔴 = cresceu (pior), 🟢 = diminuiu (melhor). null base => sem delta.
  const deltaCell = (prV, baseV) => {
    if (baseV == null) return '—';
    const d = prV - baseV;
    if (d === 0) return '= ';
    const arrow = d < 0 ? '🟢' : '🔴';
    const pct = baseV !== 0 ? ` (${((d / baseV) * 100).toFixed(1)}%)` : '';
    return `${arrow} ${d > 0 ? '+' : '−'}${kb(Math.abs(d))}${pct}`;
  };

  const lines = ['# 📦 Bundle size', ''];

  // Veredito no topo, sobre o gzip total (o que pesa na transferência).
  if (!base) {
    lines.push('> Não há baseline do `main` ainda. Os números abaixo são da PR; a comparação delta começa no próximo merge.', '');
  } else {
    const d = pr.total.gzip - base.total.gzip;
    const pct = base.total.gzip !== 0 ? (d / base.total.gzip) * 100 : 0;
    // "Regressão significativa" = gzip total cresceu > 2% E > 2 KB.
    const significant = d > 2048 && pct > 2;
    if (d <= 0) {
      lines.push(`**✅ Sem regressão de tamanho** — gzip total ${d === 0 ? 'inalterado' : '−' + kb(Math.abs(d)) + ` (${pct.toFixed(1)}%)`}.`, '');
    } else if (significant) {
      lines.push(`**⚠️ Regressão de tamanho** — gzip total **+${kb(d)} (+${pct.toFixed(1)}%)**. Verifique se o aumento é intencional (ex.: dependência nova) ou se cabe code-splitting.`, '');
    } else {
      lines.push(`**🟡 Crescimento pequeno** — gzip total +${kb(d)} (+${pct.toFixed(1)}%), abaixo do limiar de regressão (2% / 2 KB).`, '');
    }
  }

  lines.push('| Arquivo | PR (gzip) | Main (gzip) | Δ gzip | PR (raw) | Δ raw |');
  lines.push('|---|---:|---:|---|---:|---|');

  const keys = [...new Set([...Object.keys(pr.files), ...(base ? Object.keys(base.files) : [])])].sort();
  for (const k of keys) {
    const p = pr.files[k] ?? null;
    const b = base?.files[k] ?? null;
    const prGzip = p ? kb(p.gzip) : '— (removido)';
    const baseGzip = b ? kb(b.gzip) : '— (novo)';
    lines.push(
      `| \`${k}\` | ${prGzip} | ${baseGzip} | ${p && b ? deltaCell(p.gzip, b.gzip) : '—'} | ${p ? kb(p.raw) : '—'} | ${p && b ? deltaCell(p.raw, b.raw) : '—'} |`
    );
  }

  const baseTotalGzip = base ? kb(base.total.gzip) : '—';
  lines.push(
    `| **Total** | **${kb(pr.total.gzip)}** | **${baseTotalGzip}** | ${base ? deltaCell(pr.total.gzip, base.total.gzip) : '—'} | **${kb(pr.total.raw)}** | ${base ? deltaCell(pr.total.raw, base.total.raw) : '—'} |`
  );
  lines.push('');
  const budgets = loadBudgets(budgetsPath);
  if (budgets) lines.push(...budgetLines(pr, budgets).lines);
  lines.push('<sub>gzip nível 9. 🟢 = menor que o main · 🔴 = maior. O delta é report-only; estourar o orçamento reprova o job `measure`.</sub>');

  process.stdout.write(lines.join('\n') + '\n');
  process.exit(0);
}

console.error(`Comando desconhecido: ${cmd ?? '(nenhum)'}. Use "measure" ou "compare".`);
process.exit(2);
