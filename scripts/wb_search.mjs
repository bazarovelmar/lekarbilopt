import { createRequire } from "module";
const require = createRequire(import.meta.url);
const { chromium } = require("playwright");

const query = process.argv[2];
const limit = Number(process.argv[3] || "5");

if (!query) {
  console.error("Query is required");
  process.exit(2);
}

const searchUrl = `https://www.wildberries.ru/catalog/0/search.aspx?search=${encodeURIComponent(query)}`;

const browser = await chromium.launch({ headless: true });
const page = await browser.newPage({
  userAgent:
    "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36",
});

try {
  await page.goto(searchUrl, { waitUntil: "domcontentloaded", timeout: 30000 });
  await page.waitForTimeout(2000);

  const items = await page.evaluate((limit) => {
    const cards = Array.from(document.querySelectorAll("article.product-card")).slice(0, limit);
    return cards
      .map((card) => {
        const link = card.querySelector("a.product-card__link");
        const title = card.querySelector(".product-card__name");
        const price = card.querySelector(".price__lower-price");

        const url = link?.getAttribute("href") || null;
        return {
          title: title?.textContent?.trim() || null,
          url: url ? (url.startsWith("http") ? url : `https://www.wildberries.ru${url}`) : null,
          price: price?.textContent?.trim() || null,
        };
      })
      .filter((item) => item.title && item.url);
  }, limit);

  console.log(JSON.stringify(items));
} catch (err) {
  console.error(String(err));
  process.exit(1);
} finally {
  await page.close();
  await browser.close();
}
