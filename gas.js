// WAF回避版：通常のページリクエストとして送信
function sendCouponDataAlternative() {
  const sheet = SpreadsheetApp.getActiveSpreadsheet().getSheetByName("Coupons");
  if (!sheet) return;

  const lastRow = sheet.getLastRow();
  if (lastRow < 6) return;

  // 1ヶ月分の期限(AN列)取得する
  const lastCol = 40;
  const data = sheet.getRange(6, 1, lastRow - 5, lastCol).getValues();

  // 為替レート取得（E2セルの表示値を取得）
  const exchangeRateCell = sheet.getRange("E2");
  const exchangeRate = exchangeRateCell.getDisplayValue();

  // 実行時刻を取得
  const now = new Date();
  const executionTime = `${now.getHours()}:${now
    .getMinutes()
    .toString()
    .padStart(2, "0")}`;

  // G列（コード列）のリッチテキスト情報を取得
  const couponRange = sheet.getRange(6, 7, lastRow - 5, 1);
  const richTextValues = couponRange.getRichTextValues();

  const items = data.map(function (row, index) {
    const discountAmountInDollar = row[3]; // D列: 割引額-ドル表記
    const discountRate = row[4]; // E列: 割引率
    const discountAmountInYen = row[5]; // F列: 割引額-円表記
    const coupon = row[6]; // G列: コード
    const validPeriod = row[7]; // H列: 有効期限

    // E列: 割引率から注文額と割引額に分ける
    // 注文額
    const orderMatch = discountAmountInYen.match(/(\d+)円以上/);
    const orderPrice =
      orderMatch && orderMatch[1] ? parseInt(orderMatch[1], 10) : 0;
    // 割引額の抽出
    const discountMatch = discountAmountInYen.match(/(\d+)円OFF/);
    const discountPrice =
      discountMatch && discountMatch[1] ? parseInt(discountMatch[1], 10) : 0;

    // ハイパーリンクのURL取得
    const richText = richTextValues[index][0];
    const couponUrl =
      richText && richText.getLinkUrl ? richText.getLinkUrl() : "";

    // I-AN列の有効期限の詳細
    const validPeriodDetails = {};
    const today = new Date();
    for (var i = 8; i <= 39; i++) {
      if (row[i] !== undefined && row[i] !== null && row[i] !== "") {
        const targetDate = new Date(today);
        targetDate.setDate(today.getDate() + (i - 8));

        const dateKey =
          targetDate.getFullYear().toString() +
          (targetDate.getMonth() + 1).toString().padStart(2, "0") +
          targetDate.getDate().toString().padStart(2, "0");

        validPeriodDetails[dateKey] = row[i];
      }
    }

    return {
      discountAmountInDollar,
      discountRate,
      discountAmountInYen,
      orderPrice,
      discountPrice,
      coupon,
      couponUrl,
      validPeriod,
      validPeriodDetails,
    };
  });

  const payload = JSON.stringify({
    items,
    exchangeRate,
    executionTime,
  });

  // URLにパラメータを追加してWAFを回避。POSTではWAFでサーバーに弾かれるためこの方法を採用
  const url =
    "https://ali-guide.com/?coupon_update=gas_update&api_key=aliex_coupon_gas_secret_key";

  const options = {
    method: "POST",
    contentType: "application/json",
    payload: payload,
    headers: {
      "User-Agent":
        "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36",
      Accept:
        "text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8",
      "Accept-Language": "ja,en-US;q=0.7,en;q=0.3",
      "Cache-Control": "no-cache",
    },
    muteHttpExceptions: true,
  };

  try {
    const response = UrlFetchApp.fetch(url, options);
    if (response.getResponseCode() !== 200) {
      throw new Error(
        `HTTP ${response.getResponseCode()}: ${response.getContentText()}`
      );
    }
  } catch (error) {
    console.log(`API Error:${error.toString()}`);
    throw error;
  }
}

// テスト用関数
function testCouponDataAlternative() {
  const testData = {
    items: [
      {
        discountAmountInDollar: "$29以上で$3割引",
        discountRate: 0.10344,
        discountAmountInYen: "4279円以上で443円OFF",
        orderPrice: 4279,
        discountPrice: 443,
        coupon: "ABC123",
        couponUrl: "https://s.click.aliexpress.com/e/_oCd2anY",
        validPeriod: "7/14(月)16時〜7/21(月)16時",
        validPeriodDetails: {
          20250713: "○",
          20250714: "×",
          20250715: "○",
        },
      },
    ],
  };

  var payload = JSON.stringify(testData);
  var url =
    "https://ali-guide.com/?coupon_update=gas_update&api_key=aliex_coupon_gas_secret_key";

  var options = {
    method: "POST",
    contentType: "application/json",
    payload: payload,
    headers: {
      "User-Agent":
        "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36",
      Accept:
        "text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8",
      "Accept-Language": "ja,en-US;q=0.7,en;q=0.3",
    },
    muteHttpExceptions: true,
  };

  try {
    var response = UrlFetchApp.fetch(url, options);
    if (response.getResponseCode() !== 200) {
      throw new Error(
        `HTTP ${response.getResponseCode()}: ${response.getContentText()}`
      );
    }
  } catch (error) {
    console.log(`Test Error:${error.toString()}`);
    throw error;
  }
}
