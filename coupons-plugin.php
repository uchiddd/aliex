<?php
/**
 * Plugin Name:       Find Coupon From Spreadsheet (WAF対応)
 * Description:       GAS連携でクーポン情報を更新してショートコードで表示するプラグイン
 * Version:           1.1
 * Author:            hiroki
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// GAS からのリクエストを処理
add_action('init', function() {
    if (isset($_GET['coupon_update']) && $_GET['coupon_update'] === 'gas_update') {
        aliex_handle_coupon_update_via_get();
        exit;
    }
});


// GAS連携でクーポンデータを更新する関数
function aliex_handle_coupon_update_via_get() {
    // セキュリティチェック
    if (!isset($_GET['api_key']) || $_GET['api_key'] !== 'aliex_coupon_gas_secret_key') {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Invalid API key']);
        return;
    }
    
    // JSONデータを受信
    $input = file_get_contents('php://input');
    if (empty($input)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Empty request body']);
        return;
    }
    
    $data = json_decode($input, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid JSON: ' . json_last_error_msg()]);
        return;
    }
    
    if (!$data || !isset($data['items']) || !is_array($data['items'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Missing or invalid items data']);
        return;
    }
    
    // データを保存
    update_option('coupon_data', $data['items']);
    
    // 為替レートと実行時刻を保存
    if (isset($data['exchangeRate'])) {
        update_option('coupon_exchange_rate', $data['exchangeRate']);
    }
    if (isset($data['executionTime'])) {
        update_option('coupon_execution_time', $data['executionTime']);
    }
    
    echo json_encode(['success' => true, 'message' => 'Data updated successfully']);
}

/** 
 * 割引率の計算
 * 15.0　% 以上は強調表示
 */
function aliex_calculate_discount_data($item) {
    // データ検証
    if (!isset($item['discountRate'])) {
        return [
            'formatted' => '未設定',
            'isHigh' => false
        ];
    }
    
    $discountRateNum = floatval($item['discountRate']) * 100;
    
    // 異常値チェック
    if ($discountRateNum < 0 || $discountRateNum > 100) {
        return [
            'formatted' => 'エラー',
            'isHigh' => false
        ];
    }
    
    $discountRateFormatted = number_format($discountRateNum, 1) . '%';
    $isHighDiscount = $discountRateNum >= 15.0;
    
    return [
        'formatted' => $discountRateFormatted,
        'isHigh' => $isHighDiscount
    ];
}

/**
 * クーポンのセルの作成
 */
function aliex_render_coupon_cell($item, $isMobile = false) {
    // データ検証
    if (!isset($item['coupon']) || empty($item['coupon'])) {
        return '<td><span class="cell-text">コードなし</span></td>';
    }
    
    $output = '<td><div class="coupon-container">';
    
    if (!empty($item['couponUrl'])) {
        $output .= '<strong><a class="in-cell-link" href="'.esc_url($item['couponUrl']).'" target="_blank" rel="noopener">'.esc_html($item['coupon']).'</a></strong>';
    } else {
        $output .= '<strong>'.esc_html($item['coupon']).'</strong>';
    }
    
    // コピーアイコン（モバイルサイズ450px以下では小さいアイコンを使用）
    if ($isMobile) {
        $svgUrl = 'https://www.ali-guide.com/wp-content/uploads/2025/07/content_copy_16dp_1F1F1F_FILL0_wght200_GRAD0_opsz20.svg';
    } else {
        $svgUrl = 'https://www.ali-guide.com/wp-content/uploads/2025/07/content_copy_24dp_1F1F1F_FILL0_wght400_GRAD0_opsz24.svg';
    }
    
    $svgPath = str_replace(get_site_url(), ABSPATH, $svgUrl);
    if (file_exists($svgPath)) {
        $svgContent = file_get_contents($svgPath);
        $output .= '<span class="copy-icon" onclick="copyToClipboard(\''.esc_js($item['coupon']).'\', this)" title="コードをコピー">' . $svgContent . '</span>';
    } else {
        // SVGファイルがない場合はテキストボタン
        $output .= '<span class="copy-icon" onclick="copyToClipboard(\''.esc_js($item['coupon']).'\', this)" title="コードをコピー" style="background:#f0f0f0;padding:2px 4px;border:1px solid #ccc;border-radius:3px;font-size:10px;">コピー</span>';
    }
    
    $output .= '</div></td>';
    return $output;
}

/**
 * テーブルヘッダーの作成　
 */
function aliex_render_table_header($columns) {
    $output = '<tr>';
    foreach ($columns as $column) {
        $output .= '<th><span class="header-text"><strong>'.esc_html($column).'</strong></span></th>';
    }
    $output .= '</tr>';
    return $output;
}

/**
 * CSSスタイル 
 */
add_action('wp_head', function() {
    echo '<style>
    /* ==================== 共通テーブルスタイル ==================== */
    .coupon-table {
        border-collapse: collapse;
        margin: 0;
        white-space: nowrap;
    }
    
    /* ==================== デスクトップレイアウト ==================== */
    .coupon-table-container {
        display: flex;
        width: 100%;
        position: relative;
    }
    
    .fixed-columns {
        flex-shrink: 0;
    }
    
    .scrollable-columns {
        overflow-x: auto;
        flex-grow: 1;
        position: relative;
    }
    
    .scrollable-columns table {
        width: 100%;
    }
    
    /* ==================== スクロールインジケーター ==================== */
    .scroll-indicator,
    .mobile-scroll-indicator {
        position: absolute;
        top: 0;
        right: 0;
        bottom: 0;
        background: linear-gradient(to left, rgba(255,255,255,0.9) 0%, transparent 100%);
        pointer-events: none;
        z-index: 2;
        transition: opacity 0.3s ease;
    }
    
    .scroll-indicator.hidden,
    .mobile-scroll-indicator.hidden {
        opacity: 0;
    }
    
    .coupon-table-container .scroll-indicator {
        width: 30px;
    }
    
    /* ==================== テーブルセル共通スタイル ==================== */
    .coupon-table th {
        background-color: #e62e02;
        border: 1px solid black;
        text-align: center;
        vertical-align: middle;
        box-sizing: border-box;
    }
    
    .coupon-table td {
        border: 1px solid #000000;
        text-align: center;
        height: 48px;
        vertical-align: middle;
        background-color: #ffffff;
        white-space: nowrap;
        box-sizing: border-box;
    }
    
    /* ==================== テキストスタイル ==================== */
    .header-text {
        color: #ffffff;
        font-size: 16px;
        font-weight: bold;
    }
    
    .cell-text {
        font-size: 14px;
    }
    
    .exchange-info {
        margin: 0;
        font-size: 18px;
        font-weight: bold;
        color: #333;
    }
    
    /* ==================== インタラクティブ要素 ==================== */
    .coupon-table .in-cell-link {
        font-weight: bold;
        color: #0066cc;
        text-decoration: underline;
        cursor: pointer;
        transition: color 0.3s ease;
    }
    
    .coupon-table .in-cell-link:hover {
        color: #e62e02;
    }
    
    .copy-icon {
        cursor: pointer;
    }
    
    .coupon-table .high-discount {
        color: #e62e02;
        font-weight: bold;
    }
    
    .copy-info {
        position: fixed;
        top: 50%;
        left: 50%;
        transform: translate(-50%, -50%);
        background-color: #28a745;
        color: white;
        padding: 12px 24px;
        border-radius: 6px;
        z-index: 1000;
        display: none;
        font-weight: bold;
        box-shadow: 0 4px 12px rgba(0,0,0,0.15);
    }
    
    .no-coupon {
        text-align: center;
        color: #666;
        font-style: italic;
        padding: 30px;
        background-color: #f9f9f9;
        border: 1px solid #ccc;
        margin: 20px 0;
    }
    
    /* ==================== モバイルレイアウト ==================== */
    .mobile-table-container {
        display: none;
        position: relative;
    }
    
    .mobile-table {
        display: none;
    }
    
    .mobile-table table {
        width: 100%;
        min-width: 100%;
    }
    
    /* ==================== レスポンシブ対応 ==================== */
    @media (max-width: 450px) {
        .coupon-table-container,
        .fixed-columns,
        .scrollable-columns {
            display: none !important;
        }
        
        .mobile-table-container {
            display: block !important;
            position: relative;
        }
        
        .mobile-table {
            display: block !important;
            width: 100%;
            overflow-x: auto;
            position: relative;
        }
        
        .mobile-scroll-indicator-fixed {
            position: absolute !important;
            top: 0 !important;
            right: 0 !important;
            bottom: 0 !important;
            width: 20px !important;
            background: linear-gradient(to left, rgba(255,255,255,0.9) 0%, transparent 100%) !important;
            pointer-events: none !important;
            z-index: 2 !important;
            transition: opacity 0.3s ease !important;
            display: block !important;
        }
        
        .mobile-scroll-indicator-fixed.hidden {
            opacity: 0 !important;
        }
        
        .mobile-table table {
            border-collapse: collapse;
            width: 100%;
            min-width: 100%;
        }
        
        .mobile-table .coupon-table th span {
            font-size: 12px;
        }
        
        .mobile-table .coupon-table td {
            height: 40px;
            font-size: 11px;
            padding: 5px 3px;
        }
    }
    </style>';
});

add_shortcode('coupon_table', function() {
  $items = get_option('coupon_data');
  if (!$items) return '<div class="no-coupon">現在のクーポン情報がありません。</div>';
  
  // 為替レートと実行時刻を取得
  $exchangeRate = get_option('coupon_exchange_rate', '');
  $executionTime = get_option('coupon_execution_time', '');
  
  // 空の行（couponが空）をフィルタリング
  $filteredItems = array_filter($items, function($item) {
    return !empty($item['coupon']);
  });
  
  if (empty($filteredItems)) return '<div class="no-coupon">現在のクーポン情報がありません。</div>';
  
  // orderPrice（注文額）の昇順でソート、同じ場合はdiscountPrice（割引額）の昇順でソート
  usort($filteredItems, function($a, $b) {
    $priceA = floatval(str_replace(['¥', ','], '', $a['orderPrice'] ?? '0'));
    $priceB = floatval(str_replace(['¥', ','], '', $b['orderPrice'] ?? '0'));
    
    // orderPriceが同じ場合はdiscountPriceで比較
    if ($priceA === $priceB) {
      $discountA = floatval(str_replace(['¥', ','], '', $a['discountPrice'] ?? '0'));
      $discountB = floatval(str_replace(['¥', ','], '', $b['discountPrice'] ?? '0'));
      return $discountA <=> $discountB;
    }
    
    return $priceA <=> $priceB;
  });
  
  // 日付カラムのヘッダーを生成
  $dateHeaders = [];
  $sampleItem = reset($filteredItems);
  if (isset($sampleItem['validPeriodDetails']) && is_array($sampleItem['validPeriodDetails'])) {
    $dateKeys = array_keys($sampleItem['validPeriodDetails']);
    sort($dateKeys); // 日付順にソート
    
    foreach ($dateKeys as $index => $dateKey) {
      if ($index === 0) {
        $dateHeaders[] = '本日';
      } else {
        $month = substr($dateKey, 4, 2);
        $day = substr($dateKey, 6, 2);
        $dateHeaders[] = intval($month) . '/' . intval($day);
      }
    }
  }
  
  ob_start();
  
  // 為替レート情報をテーブルの上に表示
  $exchangeInfo = '';
  if ($executionTime && $exchangeRate) {
    $exchangeInfo = '(' . $exchangeRate . ' 【' . $executionTime . ' 更新】)';
  } else if ($exchangeRate) {
    $exchangeInfo = '(' . $exchangeRate . ')';
  }
  echo '<div class="exchange-info">' . esc_html($exchangeInfo) . '</div>';
  
  echo '<div class="coupon-table-container">';
  echo '<div class="scroll-indicator" id="scroll-indicator"></div>';

  /**
   * Webブラウザ用
   */
  echo '<div class="fixed-columns">';
  echo '<table class="coupon-table">';
  echo '<tbody>';
  
  // 固定列のヘッダー
  echo aliex_render_table_header(['クーポン', '注文額', '割引額', '割引率']);
  
  // 固定列のデータ行
  foreach ($filteredItems as $item) {
    $discountData = aliex_calculate_discount_data($item);
    
    echo '<tr>';
    
    // 1. クーポン
    echo aliex_render_coupon_cell($item);

    // 2. 注文額
    echo '<td><span class="cell-text">¥'.esc_html($item['orderPrice']).'</span></td>';

    // 3. 割引額
    echo '<td><span class="cell-text">¥'.esc_html($item['discountPrice']).'</span></td>';

    // 4. 割引率
    $discountClass = $discountData['isHigh'] ? 'high-discount' : '';
    echo '<td><span class="cell-text '.$discountClass.'">'.$discountData['formatted'].'</span></td>';
    
    echo '</tr>';
  }
  
  echo '</tbody>';
  echo '</table>';
  echo '</div>';
  
  // スクロール列（日付列）
  echo '<div class="scrollable-columns" id="scrollable-area">';
  echo '<table class="coupon-table">';
  echo '<tbody>';
  
  // スクロール列のヘッダー
  echo aliex_render_table_header($dateHeaders);
  
  // スクロール列のデータ行
  foreach ($filteredItems as $item) {
    
    echo '<tr>';
    
    // 5. 有効期限の詳細
    if (isset($item['validPeriodDetails']) && is_array($item['validPeriodDetails'])) {
      $dateKeys = array_keys($item['validPeriodDetails']);
      sort($dateKeys);
      
      foreach ($dateKeys as $dateKey) {
        $value = $item['validPeriodDetails'][$dateKey];
        echo '<td><span class="cell-text">'.esc_html($value).'</span></td>';
      }
    }
    
    echo '</tr>';
  }
  
  echo '</tbody>';
  echo '</table>';
  echo '</div>';
  echo '</div>';

  /**
   * モバイル用テーブル（450px以下）
   */
  echo '<div class="mobile-table-container">';
  echo '<div class="mobile-scroll-indicator-fixed" id="mobile-scroll-indicator"></div>';
  echo '<div class="mobile-table" id="mobile-table">';
  echo '<table class="coupon-table">';
  echo '<tbody>';
  
  // モバイル用ヘッダー
  echo aliex_render_table_header(['クーポン', '注文額', '割引額' ,'割引率', '有効期限']);
  
  // モバイル用データ行
  foreach ($filteredItems as $item) {
    $discountData = aliex_calculate_discount_data($item);
    
    echo '<tr>';
    
    // 1. クーポン
    echo aliex_render_coupon_cell($item, true);

    // 2. 注文額
    echo '<td><span class="cell-text">¥'.esc_html($item['orderPrice']).'</span></td>';

    // 3. 割引額
    echo '<td><span class="cell-text">¥'.esc_html($item['discountPrice']).'</span></td>';

    // 4. 割引率
    $discountClass = $discountData['isHigh'] ? 'high-discount' : '';
    echo '<td><span class="cell-text '.$discountClass.'">'.$discountData['formatted'].'</span></td>';
    
    // 5. 有効期間
    $validPeriod = isset($item['validPeriod']) ? $item['validPeriod'] : '未設定';
    echo '<td><span class="cell-text">'.esc_html($validPeriod).'</span></td>';
    
    echo '</tr>';
  }
  
  echo '</tbody>';
  echo '</table>';
  echo '</div>';
  echo '</div>';

  /**
   * JavaScript
   */
  echo '<div class="copy-info" id="copy-info">コードをコピー</div>';
  
  echo '<script>
  function copyToClipboard(text, button) {
    navigator.clipboard.writeText(text).then(function() {
      // コピー完了メッセージ表示
      const copyInfo = document.getElementById("copy-info");
      copyInfo.style.display = "block";
      
      // 1.5秒後にメッセージを非表示
      setTimeout(function() {
        copyInfo.style.display = "none";
      }, 1500);
    }).catch(function(err) {
      console.error("コピーに失敗しました:", err);
      alert("コピーに失敗しました");
    });
  }
  
  // スクロールインジケーターの制御
  document.addEventListener("DOMContentLoaded", function() {
    const scrollArea = document.getElementById("scrollable-area");
    const indicator = document.getElementById("scroll-indicator");
    
    if (scrollArea && indicator) {
      function updateIndicator() {
        const isScrollable = scrollArea.scrollWidth > scrollArea.clientWidth;
        const isAtEnd = scrollArea.scrollLeft >= scrollArea.scrollWidth - scrollArea.clientWidth - 5;
        
        if (!isScrollable || isAtEnd) {
          indicator.classList.add("hidden");
        } else {
          indicator.classList.remove("hidden");
        }
      }
      
      // 初期状態をチェック
      updateIndicator();
      
      // スクロール時に更新
      scrollArea.addEventListener("scroll", updateIndicator);
      
      // リサイズ時に更新
      window.addEventListener("resize", updateIndicator);
    }
    
    // モバイル用スクロールインジケーター
    const mobileScrollArea = document.getElementById("mobile-table");
    const mobileIndicator = document.getElementById("mobile-scroll-indicator");
    
    if (mobileScrollArea && mobileIndicator) {
      function updateMobileIndicator() {
        const isScrollable = mobileScrollArea.scrollWidth > mobileScrollArea.clientWidth;
        const isAtEnd = mobileScrollArea.scrollLeft >= mobileScrollArea.scrollWidth - mobileScrollArea.clientWidth - 5;
        
        if (!isScrollable || isAtEnd) {
          mobileIndicator.classList.add("hidden");
        } else {
          mobileIndicator.classList.remove("hidden");
        }
      }
      
      // 初期状態をチェック
      updateMobileIndicator();
      
      // スクロール時に更新
      mobileScrollArea.addEventListener("scroll", updateMobileIndicator);
      
      // リサイズ時に更新
      window.addEventListener("resize", updateMobileIndicator);
    }
  });
  </script>';
  
  return ob_get_clean();
});
?>