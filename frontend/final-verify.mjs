import { chromium } from 'playwright';
const BASE='http://localhost:5173';
async function unauth(p){ const b=await chromium.launch(); const pg=await b.newPage(); await pg.goto(BASE+p,{waitUntil:'domcontentloaded'}); await pg.waitForTimeout(1500); const ok=pg.url().includes('/login'); console.log(`Unauth ${p} ${ok?'PASS':'FAIL'}`); await b.close(); return ok; }
async function courses(){ const b=await chromium.launch(); const pg=await b.newPage(); await pg.goto(BASE+'/courses',{waitUntil:'domcontentloaded'}); await pg.waitForTimeout(4000); const ok=(await pg.locator('body').innerText()).includes('Showing 107 courses'); console.log(`Courses ${ok?'PASS':'FAIL'}`); await b.close(); return ok; }
async function login(email,exp){ const b=await chromium.launch(); const pg=await b.newPage(); await pg.goto(BASE+'/login',{waitUntil:'domcontentloaded'}); await pg.waitForTimeout(800); await pg.fill('input[type="email"]',email); await pg.fill('input[type="password"]','password'); await pg.click('button[type="submit"]'); await pg.waitForTimeout(4000); try{await pg.waitForURL('**'+exp,{timeout:2000});}catch{} const ok=pg.url().includes(exp); console.log(`Login ${email.split('@')[0]} ${ok?'PASS':'FAIL'} ${pg.url()}`); await b.close(); return ok; }
async function logout(){ const b=await chromium.launch(); const pg=await b.newPage(); await pg.goto(BASE+'/login',{waitUntil:'domcontentloaded'}); await pg.waitForTimeout(800); await pg.fill('input[type="email"]','student@example.com'); await pg.fill('input[type="password"]','password'); await pg.click('button[type="submit"]'); try{await pg.waitForURL('**/student',{timeout:6000});}catch{} await pg.waitForTimeout(500); const btn=pg.locator('button:has-text("Student Test")').first(); await btn.click(); await pg.waitForTimeout(700); await pg.locator('button:has-text("Sign Out")').first().click(); await pg.waitForTimeout(2500); const url=pg.url(); const tok=await pg.evaluate(()=>localStorage.getItem('access_token')); const ok=(url.includes('/login')||url===BASE+'/') && !tok; console.log(`Logout ${ok?'PASS':'FAIL'} ${url} token ${!tok?'cleared':'present'}`); await b.close(); return ok; }
const r=[];
r.push(await unauth('/admin')); r.push(await unauth('/student')); r.push(await unauth('/tutor'));
r.push(await courses());
r.push(await login('student@example.com','/student')); r.push(await login('admin@example.com','/admin')); r.push(await login('tutor@example.com','/tutor'));
r.push(await logout());
console.log(`Passed ${r.filter(Boolean).length}/${r.length}`);
process.exit(r.every(Boolean)?0:1);
