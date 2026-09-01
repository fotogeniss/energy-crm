#!/usr/bin/env node
/**
 * Πραγματικό HTTP concurrency load test -- ο ιδιοκτήτης το ζήτησε ρητά ξεχωριστά
 * από τις μετρήσεις κόστους ερωτημάτων: "θέλω και πραγματικό load test με
 * ταυτόχρονα requests". Διαφορά ουσίας από όλα τα tools/measure-*.php: εκείνα
 * καλούν μεθόδους απευθείας μέσα σε ΕΝΑ PHP process (wp eval-file) -- κανένα
 * πραγματικό HTTP, κανένα PHP-FPM pool, καμία πραγματική ταυτοχρονία σε επίπεδο
 * process/σύνδεσης βάσης. Αυτό εδώ ανοίγει N πραγματικές, ταυτόχρονες HTTP
 * συνδέσεις προς το ζωντανό Local site και μετράει πώς αντιδρά η στοίβα
 * (PHP-FPM + MySQL) υπό πραγματικό φόρτο -- όχι πόσο κοστίζει ένα ερώτημα.
 *
 * Χρειάζεται authentication χωρίς cookies/nonce (αυτά είναι φτιαγμένα για ένα
 * browser tab, όχι για 50 παράλληλες συνδέσεις): χρησιμοποιεί WordPress
 * Application Passwords μέσω Basic Auth -- ο πυρήνας του WP τα καταλαβαίνει
 * αυτόματα, καμία αλλαγή στο plugin. Τρέξε πρώτα:
 *
 *   wp eval-file wp-content/plugins/energy-crm/tools/measure-realistic-seed.php [target]
 *   wp eval-file wp-content/plugins/energy-crm/tools/load-test-appwd.php
 *
 * και μετά αυτό:
 *
 *   node tools/load-test-concurrency.js
 *   node tools/load-test-concurrency.js --levels=5,20,50,100 --requests=200
 *
 * Χτυπάει GET /wp-json/ecrm/v1/dashboard (σαν πωλητής, το πιο συχνό endpoint
 * στην πράξη) και GET /wp-json/ecrm/v1/team/escalations (σαν κατάστημα).
 *
 * Το πρωτόκολλο (http/https) διαβάζεται από το site_url του
 * .load-test-credentials.json, όχι υποτιθέμενο -- ένα καθαρό Local by
 * Flywheel site είναι συνήθως http://*.local χωρίς SSL καθόλου, κάποια
 * (με "Enable SSL" ενεργό) είναι https:// με self-signed πιστοποιητικό.
 * Στη δεύτερη περίπτωση το script είναι σκόπιμα ανεκτικό στο πιστοποιητικό
 * (rejectUnauthorized: false) -- ΜΟΝΟ επειδή το site είναι τοπικό dev site,
 * ποτέ δεν πρέπει να ξαναχρησιμοποιηθεί αυτό το μοτίβο πάνω σε πραγματικό
 * domain.
 *
 * Δεν γράφει τίποτα στη βάση -- GET only και στα δύο endpoints. Καθάρισε μετά
 * με το ίδιο tools/measure-realistic-cleanup.php (διαγράφει και το αρχείο
 * διαπιστευτηρίων).
 */

'use strict';

const fs = require('fs');
const path = require('path');
const http = require('http');
const https = require('https');

function parseArgs(argv) {
  const out = { levels: [5, 20, 50, 100], requestsPerLevel: 100 };
  for (const arg of argv.slice(2)) {
    const [key, value] = arg.replace(/^--/, '').split('=');
    if (key === 'levels' && value) {
      out.levels = value.split(',').map((n) => parseInt(n, 10)).filter((n) => n > 0);
    } else if (key === 'requests' && value) {
      out.requestsPerLevel = Math.max(1, parseInt(value, 10));
    } else if (key === 'creds' && value) {
      out.credsPath = value;
    }
  }
  return out;
}

function loadCredentials(credsPath) {
  const resolved = credsPath || path.join(__dirname, '.load-test-credentials.json');
  if (!fs.existsSync(resolved)) {
    console.error(`Δεν βρέθηκε αρχείο διαπιστευτηρίων: ${resolved}`);
    console.error('Τρέξε πρώτα: wp eval-file wp-content/plugins/energy-crm/tools/load-test-appwd.php');
    process.exit(1);
  }
  return JSON.parse(fs.readFileSync(resolved, 'utf8'));
}

// Local by Flywheel: αν το site είναι https://*.local έχει self-signed cert --
// τοπικό μόνο, βλ. docblock. Πάνω σε http:// (το συνηθισμένο, χωρίς "Enable
// SSL") αυτό δεν χρησιμοποιείται καθόλου.
const insecureHttpsAgent = new https.Agent({ rejectUnauthorized: false, keepAlive: true });
const httpAgent = new http.Agent({ keepAlive: true });

function requestOnce(url, auth) {
  return new Promise((resolve) => {
    const started = process.hrtime.bigint();
    const authHeader = 'Basic ' + Buffer.from(`${auth.login}:${auth.password}`).toString('base64');
    const isHttps = url.startsWith('https:');
    const transport = isHttps ? https : http;
    const agent = isHttps ? insecureHttpsAgent : httpAgent;

    const req = transport.get(
      url,
      { agent, headers: { Authorization: authHeader, Accept: 'application/json' } },
      (res) => {
        res.on('data', () => {});
        res.on('end', () => {
          const ms = Number(process.hrtime.bigint() - started) / 1e6;
          resolve({ ok: res.statusCode >= 200 && res.statusCode < 300, status: res.statusCode, ms });
        });
      }
    );

    req.on('error', (err) => {
      const ms = Number(process.hrtime.bigint() - started) / 1e6;
      resolve({ ok: false, status: 0, ms, error: err.message });
    });
  });
}

function percentile(sorted, p) {
  if (sorted.length === 0) return 0;
  const idx = Math.min(sorted.length - 1, Math.ceil((p / 100) * sorted.length) - 1);
  return sorted[Math.max(0, idx)];
}

async function runLevel(endpoint, restUrl, credentials, concurrency, totalRequests) {
  const results = [];
  let sent = 0;

  async function worker() {
    while (sent < totalRequests) {
      const my = sent++;
      const auth = credentials[my % credentials.length];
      const url = restUrl + endpoint;
      results.push(await requestOnce(url, auth));
    }
  }

  const workers = Array.from({ length: Math.min(concurrency, totalRequests) }, worker);
  const start = process.hrtime.bigint();
  await Promise.all(workers);
  const wallMs = Number(process.hrtime.bigint() - start) / 1e6;

  const durations = results.map((r) => r.ms).sort((a, b) => a - b);
  const errors = results.filter((r) => !r.ok);

  return {
    concurrency,
    totalRequests,
    wallMs,
    min: durations[0] || 0,
    p50: percentile(durations, 50),
    p95: percentile(durations, 95),
    max: durations[durations.length - 1] || 0,
    avg: durations.reduce((a, b) => a + b, 0) / (durations.length || 1),
    errors: errors.length,
    throughputPerSec: (totalRequests / wallMs) * 1000,
    sampleErrors: errors.slice(0, 3),
  };
}

async function main() {
  const args = parseArgs(process.argv);
  const { site_url: siteUrl, rest_url: restUrl, credentials } = loadCredentials(args.credsPath);

  if (!credentials || credentials.length === 0) {
    console.error('Το αρχείο διαπιστευτηρίων δεν έχει κανέναν λογαριασμό.');
    process.exit(1);
  }

  console.log(`Site: ${siteUrl}`);
  console.log(`REST: ${restUrl}`);
  console.log(`${credentials.length} συνθετικοί λογαριασμοί με Application Password.\n`);

  // /team/escalations είναι πίσω από MANAGE_TEAM (Guards::needs) -- μόνο ο
  // ρόλος 'store' το έχει, οι 'seller' παίρνουν σωστά 403 (ασφάλεια που
  // δουλεύει όπως πρέπει, όχι σφάλμα φόρτου). Αν το χτυπήσουμε round-robin
  // με όλους τους 11 λογαριασμούς, ~90% θα αποτύχουν ΠΑΝΤΑ, ανεξάρτητα από
  // ταυτοχρονία -- θα έκρυβε το πραγματικό εύρημα φόρτου. Φιλτράρουμε ανά
  // endpoint στους λογαριασμούς που πραγματικά έχουν δικαίωμα να το δουν.
  const endpoints = [
    { path: 'dashboard', credentials },
    {
      path: 'team/escalations',
      credentials: credentials.filter((c) => c.role === 'store'),
    },
  ];

  for (const { path: endpoint, credentials: endpointCreds } of endpoints) {
    if (endpointCreds.length === 0) {
      console.log(`=== GET /${endpoint} === -- παραλείπεται, κανένας λογαριασμός με δικαίωμα\n`);
      continue;
    }

    console.log(`=== GET /${endpoint} === (${endpointCreds.length} εξουσιοδοτημένος λογαριασμός/οί)`);
    console.log('ταυτόχρονα  requests   wall ms   avg ms   p50 ms   p95 ms   max ms   req/s   σφάλματα');

    for (const level of args.levels) {
      const r = await runLevel(endpoint, restUrl, endpointCreds, level, args.requestsPerLevel);
      console.log(
        `${String(r.concurrency).padStart(10)}  ${String(r.totalRequests).padStart(8)}  ` +
          `${r.wallMs.toFixed(0).padStart(7)}  ${r.avg.toFixed(1).padStart(7)}  ` +
          `${r.p50.toFixed(1).padStart(7)}  ${r.p95.toFixed(1).padStart(7)}  ` +
          `${r.max.toFixed(1).padStart(7)}  ${r.throughputPerSec.toFixed(1).padStart(6)}  ${r.errors}`
      );
      if (r.errors > 0) {
        for (const e of r.sampleErrors) {
          console.log(`    -> status ${e.status}${e.error ? ' (' + e.error + ')' : ''}`);
        }
      }
    }
    console.log('');
  }

  console.log('Θυμήσου να τρέξεις μετά:');
  console.log('wp eval-file wp-content/plugins/energy-crm/tools/measure-realistic-cleanup.php');
}

main();
