<?php 
//汎用関数ファイル読み込み
require_once MODEL_PATH . 'functions.php';
//dbデータに関するファイル読み込み
require_once MODEL_PATH . 'db.php';

//カートに入っているアイテムをitemsとcartsテーブルからユーザーごとに表示
function get_user_carts($db, $user_id){
  $sql = "
    SELECT
      items.item_id,
      items.name,
      items.price,
      items.stock,
      items.status,
      items.image,
      carts.cart_id,
      carts.user_id,
      carts.amount
    FROM
      carts
    JOIN
      items
    ON
      carts.item_id = items.item_id
    WHERE
      carts.user_id = ?
  ";
  //DBのSQLを実行し全ての結果行レコード取得
  return fetch_all_query($db, $sql, $params = array($user_id));
}

//itemsとcartsテーブルをユーザーとアイテムごとに表示
function get_user_cart($db, $user_id, $item_id){
  $sql = "
    SELECT
      items.item_id,
      items.name,
      items.price,
      items.stock,
      items.status,
      items.image,
      carts.cart_id,
      carts.user_id,
      carts.amount
    FROM
      carts
    JOIN
      items
    ON
      carts.item_id = items.item_id
    WHERE
      carts.user_id = ?
    AND
      items.item_id = ?
  ";
  //DBのSQLを実行し１行のみレコード取得
  return fetch_query($db, $sql, $params = array($user_id, $item_id));
}

//カートにアイテムを入れて数量を変更する
function add_cart($db, $user_id, $item_id ) {
  //itemsとcartsテーブルをユーザーとアイテムごとに表示
  $cart = get_user_cart($db, $user_id, $item_id);
  //$cartが無かった場合
  if($cart === false){
    //DBのカートごとにテーブルを作成
    return insert_cart($db, $user_id, $item_id);
  }
  //DBのカート内のアイテム数量を変更
  return update_cart_amount($db, $cart['cart_id'], $cart['amount'] + 1);
}

//DBのカートごとにテーブルを作成
function insert_cart($db, $user_id, $item_id, $amount = 1){
  $sql = "
    INSERT INTO
      carts(
        item_id,
        user_id,
        amount
      )
    VALUES(?, ?, ?)
  ";
  //SQLを実行
  return execute_query($db, $sql, $params = array($item_id, $user_id, $amount));
}

//DBのカート内のアイテム数量を変更
function update_cart_amount($db, $cart_id, $amount){
  $sql = "
    UPDATE
      carts
    SET
      amount = ?
    WHERE
      cart_id = ?
    LIMIT 1
  ";
  //SQLを実行
  return execute_query($db, $sql, $params = array($amount, $cart_id));
}

//DBカートテーブルをカートごとに削除
function delete_cart($db, $cart_id){
  $sql = "
    DELETE FROM
      carts
    WHERE
      cart_id = ?
    LIMIT 1
  ";
  //SQLを実行
  return execute_query($db, $sql, $params = array($cart_id));
}

//カート購入成功したらカートテーブル削除
function purchase_carts($db, $carts, $user_id){
  //購入する際のカートの中身チェック
  if(validate_cart_purchase($carts) === false){
    return false;
  }
  //トランザクション開始
  $db->beginTransaction();
  //ordersテーブルを作成
  $order_date = date('Y-m-d H:i:s');
  $sql = "
    INSERT INTO
      orders(
        user_id,
        order_date
      )
    VALUES(?, ?)
    ";
  //SQLを実行
  if(execute_query($db, $sql, $params = array($user_id, $order_date)) === false){
    //セッション変数にエラー表示
    set_error('注文テーブルの挿入に失敗しました。');
  }
  //作成したidを取得
  $order_id = $db->lastInsertId();
  
  foreach($carts as $cart){
    //itemsテーブルのstockをアップデート失敗した場合
    if(update_item_stock(
        $db, 
        $cart['item_id'], 
        $cart['stock'] - $cart['amount']
      ) === false){
      //セッション変数にエラー表示
      set_error($cart['name'] . 'の購入に失敗しました。');
    }
    $amount = $cart['amount'];
    $price = $cart['price'];
    $item_name = $cart['name'];
    
    //order_detailsテーブルを作成
    $sql = "
      INSERT INTO
        order_details(
          order_id,
          item_name,
          price,
          amount
        )
      VALUES(?, ?, ?, ?)
      ";
    //SQLを実行
    if(execute_query($db, $sql, $params = array($order_id, $item_name, $price, $amount)) === false){
      //セッション変数にエラー表示
      set_error('注文詳細テーブルの挿入に失敗しました。');  
    }
  }
  if(has_error() === true){
    // ロールバック処理
    $db->rollback();
  }else{
    // コミット処理
    $db->commit();
  }
  //DBカートテーブルをユーザーごとに削除
  delete_user_carts($db, $carts[0]['user_id']);
}

//DBカートテーブルをユーザーごとに削除
function delete_user_carts($db, $user_id){
  $sql = "
    DELETE FROM
      carts
    WHERE
      user_id = ?
  ";
  //SQLを実行
  execute_query($db, $sql, $params = array($user_id));
}

//カートの合計金額計算
function sum_carts($carts){
  $total_price = 0;
  foreach($carts as $cart){
    $total_price += $cart['price'] * $cart['amount'];
  }
  return $total_price;
}

//購入する際のカートの中身チェック
function validate_cart_purchase($carts){
  //カートが０だった場合
  if(count($carts) === 0){
    //セッション変数にエラー表示
    set_error('カートに商品が入っていません。');
    return false;
  }
  foreach($carts as $cart){
    //ステータスが０（非公開）の時
    if(is_open($cart) === false){
      //セッション変数にエラー表示
      set_error($cart['name'] . 'は現在購入できません。');
    }
    //在庫数が購入したい数量より少ない場合
    if($cart['stock'] - $cart['amount'] < 0){
      //セッション変数にエラー表示
      set_error($cart['name'] . 'は在庫が足りません。購入可能数:' . $cart['stock']);
    }
  }
  
  //セッション変数に値が入っている場合
  if(has_error() === true){
    return false;
  }
  return true;
}

//購入履歴をordersとorder_detailsテーブルからユーザーごとに表示
function get_user_orders($db, $user_id){
  $sql = "
    SELECT
      orders.order_id,
      orders.user_id,
      orders.order_date,
      SUM(order_details.price*order_details.amount) AS total_price
    FROM
      orders
    JOIN
      order_details
    ON
      orders.order_id = order_details.order_id
    WHERE
      orders.user_id = ?
    GROUP BY
      orders.order_id
    ORDER BY
      orders.order_id DESC
  ";
  //DBのSQLを実行し全ての結果行レコード取得
  return fetch_all_query($db, $sql, $params = array($user_id));
}

//購入履歴をordersとorder_detailsテーブルからユーザーとorder_idごとに表示
function get_user_order($db, $order_id){
  $sql = "
    SELECT
      orders.order_id,
      orders.user_id,
      orders.order_date,
      SUM(order_details.price*order_details.amount) AS total_price
    FROM
      orders
    JOIN
      order_details
    ON
      orders.order_id = order_details.order_id
    WHERE
      orders.order_id = ?
  ";
  //DBのSQLを実行し全ての結果行レコード取得
  return fetch_query($db, $sql, $params = array($order_id));
}

//購入明細をordersとorder_detailsテーブルからユーザーと購入アイテムごとに表示
function get_user_order_detail($db, $order_id){
  $sql = "
    SELECT
      orders.order_id,
      orders.user_id,
      orders.order_date,
      order_details.item_name,
      order_details.price,
      order_details.amount,
      SUM(order_details.price*order_details.amount) AS total_price
    FROM
      orders
    JOIN
      order_details
    ON
      orders.order_id = order_details.order_id
    WHERE
      orders.order_id = ?
    GROUP BY
      order_details.detail_id
  ";
  //DBのSQLを実行し全ての結果行レコード取得
  return fetch_all_query($db, $sql, $params = array($order_id));
}

//購入履歴をordersとorder_detailsテーブルから全表示
function get_all_order($db){
  $sql = "
    SELECT
      orders.order_id,
      orders.user_id,
      orders.order_date,
      SUM(order_details.price*order_details.amount) AS total_price
    FROM
      orders
    JOIN
      order_details
    ON
      orders.order_id = order_details.order_id
    GROUP BY
      orders.order_id DESC
  ";
  //DBのSQLを実行し全ての結果行レコード取得
  return fetch_all_query($db, $sql);
}

//購入明細をordersとorder_detailsテーブルから表示
function get_all_order_detail($db, $order_id){
  $sql = "
    SELECT
      orders.order_id,
      orders.user_id,
      orders.order_date,
      order_details.item_name,
      order_details.price,
      order_details.amount,
      SUM(order_details.price*order_details.amount) AS total_price
    FROM
      orders
    JOIN
      order_details
    ON
      orders.order_id = order_details.order_id
    GROUP BY
      order_details.detail_id
  ";
  //DBのSQLを実行し全ての結果行レコード取得
  return fetch_all_query($db, $sql, $params = array($user_id, $order_id));
}