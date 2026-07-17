# Service設計

## 1. 本ドキュメントの目的と対象範囲

本ドキュメントは、`00_project_policy.md`〜`07_controller_design.md`を前提とし、Service層(`ExpenseReportService`)の設計をまとめたものである。`07_controller_design.md`では、状態を変更する業務処理を`ExpenseReportService`に委譲すること、および`create`/`update`/`delete`/`submit`/`resubmit`/`approve`/`reject`という公開メソッドの存在までを決定済みだが、各メソッドの内部処理(トランザクション境界、`approval_histories`への記録内容、`rejection_reason`の更新規則、領収書ファイルの操作手順、競合更新対策、例外方針)は「Controller設計工程で決定する」「実装フェーズで決定する」として先送りされていた。本ドキュメントはこれらを確定させる。

対象範囲はServiceの設計のみとする。Serviceクラス・Controller・Policy・FormRequest・Model・Migration・Seeder・Route・Bladeの実装(コード)、およびテストの実装は行わない。

## 2. Service採用理由

`07_controller_design.md`の「なぜServiceへ処理を委譲するのか」を踏襲する。提出・再提出・承認・却下は、単なるステータスカラムの書き換えではなく、「ステータス変更」「却下理由(`rejection_reason`)の更新・クリア」「`approval_histories`への履歴追記」を1つのDBトランザクションとして必ずまとめて実行する必要がある(`04_er_diagram.md`5章)。この業務処理をControllerに直接書くと、Controllerが「HTTPの窓口」という役割を超えて業務ルールを抱え込み、肥大化する。

Serviceは**Laravel標準機能ではなく**、本プロジェクトが業務処理をControllerから分離するために採用する単純なPHPクラスである。Laravelのフレームワークとしての要求ではないため、状態変更を伴わない処理(認証、一覧・フォーム表示等)にまでServiceを用意することは、かえって「素通りするだけのクラス」を増やす過剰設計になる。そのため本設計では、状態変更・複数データの一括更新・ファイル操作を伴う処理のみをServiceの対象とし、読み取り専用処理は原則としてServiceに含めない。

## 3. Service一覧

| Service | 配置 | 対象 |
|---|---|---|
| `ExpenseReportService` | `app/Services` | 経費申請の状態変更を伴う業務処理(create/update/delete/submit/resubmit/approve/reject) |

本プロジェクトで新設するServiceは`ExpenseReportService`の1クラスのみとする。認証(ログイン/ログアウト)はLaravel標準の`Auth`ファサードで完結し、経費カテゴリ取得・一覧表示・詳細表示は読み取り専用でModelの直接参照により完結するため、これらに対応するServiceは設けない(`07_controller_design.md`の方針を維持)。

## 4. ExpenseReportServiceの責務

- 経費申請(`expense_reports`)の状態変更(作成・更新・削除・提出・再提出・承認・却下)
- 状態変更に伴う`approval_histories`への履歴追記
- 状態変更・履歴追記を1単位として扱う必要がある場合のDBトランザクション制御
- 領収書画像ファイルの保存・差し替え・削除

読み取り専用処理(一覧表示・詳細表示・カテゴリマスタ取得)は、`07_controller_design.md`の方針通りController・Modelの直接参照で完結させ、ExpenseReportServiceには含めない。

## 5. 公開メソッド一覧

| メソッド | 引数 | 戻り値 |
|---|---|---|
| `create` | `User $user, array $validated` | `ExpenseReport` |
| `update` | `ExpenseReport $expenseReport, array $validated` | `ExpenseReport` |
| `delete` | `ExpenseReport $expenseReport` | `void` |
| `submit` | `ExpenseReport $expenseReport, User $actor` | `ExpenseReport` |
| `resubmit` | `ExpenseReport $expenseReport, User $actor` | `ExpenseReport` |
| `approve` | `ExpenseReport $expenseReport, User $actor` | `ExpenseReport` |
| `reject` | `ExpenseReport $expenseReport, User $actor, ?string $comment` | `ExpenseReport` |

いずれの引数も`Illuminate\Http\Request`・FormRequest・RedirectResponse・View等のHTTP層のオブジェクトを含まない。`$validated`はFormRequestが検証済みの配列(`expense_date`, `amount`, `expense_category_id`, `payee`, `description`, `receipt_image`等)を指し、ControllerがFormRequestから取り出して渡す。`$actor`はログイン中ユーザー(`Auth::user()`)をControllerが渡す。

## 6. 各メソッドの詳細

### create

1. **目的**: 新規の経費申請を下書き(`draft`)として登録する(F-07)。
2. **呼び出し元Controller**: `ExpenseReportController@store`
3. **引数**: `User $user`(申請者), `array $validated`(expense_date, amount, expense_category_id, payee, description, receipt_image: `UploadedFile|null`)
4. **戻り値**: 作成された`ExpenseReport`
5. **事前条件**: `StoreExpenseReportRequest`によるバリデーション済み。認可は`role:employee`ミドルウェアのみで足り、Policyは利用しない(`07_controller_design.md`5章参照)。
6. **処理手順**:
   1. `receipt_image`が渡されていれば、領収書画像ファイルを保存し保存パスを得る(8章参照)。渡されていなければパスは`null`
   2. `expense_reports`に新規レコードをINSERTする(`user_id`=`$user->id`, `status`=`draft`, `rejection_reason`=`null`, 手順1で得たパスを`receipt_image_path`に設定)
   3. 作成された`ExpenseReport`を返す
7. **更新対象テーブル**: `expense_reports`
8. **DBトランザクションの要否**: 不要(単一テーブルへの単一INSERTであり、DB操作自体は単一ステートメントとして原子性を持つため)。8章参照。
9. **領収書ファイルの処理**: 8章参照(新規保存のみ、差し替え・削除は発生しない)。
10. **approval_historiesへの記録内容**: なし。新規作成は状態遷移ではなく(遷移元ステータスが存在しない)、`04_er_diagram.md`5章の方針により記録対象外。
11. **rejection_reasonの更新規則**: `null`固定(却下されたことがないため)。
12. **処理失敗時の扱い**: 領収書ファイルの保存に失敗した場合は例外を伝播させ、INSERTを行わない(中途半端な下書きを作らない)。INSERT自体が失敗した場合は例外を伝播させる。手順1(ファイル保存)後に手順2(INSERT)が失敗した場合、保存済みファイルが孤立する可能性があるが、MVPでは許容する(8章参照)。
13. **Serviceでは行わない処理**: 認可判定、入力値の形式検証、redirect/viewの生成。

### update

1. **目的**: 下書きまたは却下された申請の内容を更新する。ステータスは変更しない(F-08)。
2. **呼び出し元Controller**: `ExpenseReportController@update`
3. **引数**: `ExpenseReport $expenseReport`, `array $validated`(create同様の入力項目。領収書画像を差し替える場合は`receipt_image`、既存画像を削除する場合は`remove_receipt_image`(boolean)を含む)
4. **戻り値**: 更新後の`ExpenseReport`
5. **事前条件**: `UpdateExpenseReportRequest::authorize()`が`ExpenseReportPolicy@update`へ委譲した認可判定(オーナー一致、ステータスが`draft`または`rejected`)を通過済み。
6. **処理手順**:
   1. 領収書画像の状態を判定する: 新規`receipt_image`が渡されていれば「差し替え」、新規画像がなく`remove_receipt_image`が`true`であれば「削除」、どちらでもなければ「変更なし」とする(優先順位は8章参照)
   2. 「差し替え」の場合、新しい画像ファイルを保存する(この時点では旧ファイルにはまだ触れない)
   3. `expense_reports`の該当行をUPDATEする(expense_date, amount, expense_category_id, payee, description を更新。`receipt_image_path`は手順1の判定結果に応じて新パス/`null`/変更なしのいずれかを設定。`status`・`rejection_reason`は変更しない)
   4. 「差し替え」または「削除」の場合、DB更新の成功を確認したうえで旧ファイルを削除する(保存→DB更新→旧ファイル削除、という順序を必ず守る)
   5. 更新後の`ExpenseReport`を返す
7. **更新対象テーブル**: `expense_reports`
8. **DBトランザクションの要否**: 不要(単一テーブルへの単一UPDATEであり、DB操作自体は単一ステートメントとして原子性を持つため)。ファイル操作はDBトランザクションで保護できない(ファイルシステムはDBの外側のリソースであるため)。8章参照。
9. **領収書ファイルの処理**: 8章参照。
10. **approval_historiesへの記録内容**: なし。`update`はステータスを変更しないため、`04_er_diagram.md`5章の方針により記録対象外。
11. **rejection_reasonの更新規則**: 変更しない(却下状態での編集後もステータス・却下理由とも維持され、再提出時に初めてクリアされる)。
12. **処理失敗時の扱い**: 新ファイルの保存に失敗した場合は例外を伝播させ、DB更新を行わない。DB更新が失敗した場合は例外を伝播させ、旧ファイルは削除しない(削除は手順4でDB更新成功後にのみ行うため)。旧ファイルの削除に失敗した場合、DBは既に正しい状態のため、孤立ファイルが残るのみで許容する。
13. **Serviceでは行わない処理**: 認可判定(`ExpenseReportPolicy@update`への委譲はFormRequestが行う)、入力値の形式検証、redirect/viewの生成。

### delete

1. **目的**: 下書き状態の申請を削除する(F-09)。
2. **呼び出し元Controller**: `ExpenseReportController@destroy`
3. **引数**: `ExpenseReport $expenseReport`
4. **戻り値**: `void`
5. **事前条件**: Controllerが`Gate::authorize('delete', $expenseReport)`によりオーナー一致・`draft`状態であることを確認済み。
6. **処理手順**:
   1. `expense_reports`から該当行をDELETEする
   2. DELETEが成功した場合、添付されていた領収書画像ファイルを削除する(添付がなければ何もしない)
7. **更新対象テーブル**: `expense_reports`
8. **DBトランザクションの要否**: 不要(単一テーブルへの単一DELETEであり、DB操作自体は単一ステートメントとして原子性を持つため)。`draft`状態の申請は提出されたことがなく`approval_histories`に対応する行が存在しないため、他テーブルへの影響もない。
9. **領収書ファイルの処理**: 8章参照(DB削除後にファイル削除)。
10. **approval_historiesへの記録内容**: なし(削除は状態遷移ではなく、`draft`は提出履歴を持たないため)。
11. **rejection_reasonの更新規則**: 該当なし(レコード自体を削除するため)。
12. **処理失敗時の扱い**: DB削除が失敗した場合は例外を伝播させ、ファイル削除は行わない(レコードが残っている以上、参照先ファイルを残す)。DB削除成功後にファイル削除が失敗した場合、DBは既に正しい状態(レコードは存在しない)のため、孤立ファイルが残るのみで許容する。
13. **Serviceでは行わない処理**: 認可判定、redirect/viewの生成。

### submit

1. **目的**: 下書きの申請を提出済みにする(F-10)。
2. **呼び出し元Controller**: `ExpenseReportController@submit`
3. **引数**: `ExpenseReport $expenseReport`, `User $actor`(申請者本人)
4. **戻り値**: 更新後の`ExpenseReport`
5. **事前条件**: Controllerが`Gate::authorize('submit', $expenseReport)`によりオーナー一致・`draft`状態であることを確認済み。Serviceはこの認可判定を信頼し、状態の再確認は行わない。
6. **処理手順**:
   1. DBトランザクションを開始する
   2. `expense_reports.status`を`submitted`に更新する
   3. `approval_histories`へ1行追加する(9章参照)
   4. トランザクションを確定する
   5. 更新後の`ExpenseReport`を返す
7. **更新対象テーブル**: `expense_reports`, `approval_histories`
8. **DBトランザクションの要否**: 必要(2テーブルへの更新を1単位として扱うため)。
9. **領収書ファイルの処理**: なし(submitはファイルを扱わない)。
10. **approval_historiesへの記録内容**: 9章参照。
11. **rejection_reasonの更新規則**: 変更しない。
12. **処理失敗時の扱い**: DB更新が失敗した場合は例外を伝播させ、トランザクションはロールバックされる(通常の`QueryException`等としてLaravel標準の例外処理に委ねる。12章参照)。
13. **Serviceでは行わない処理**: 認可判定(オーナー一致・ステータス条件の判定はPolicyの責務)、入力値の形式検証、redirect/viewの生成。

### resubmit

1. **目的**: 却下された申請を、修正内容確認のうえ再度提出済みにする(F-11)。
2. **呼び出し元Controller**: `ExpenseReportController@resubmit`
3. **引数**: `ExpenseReport $expenseReport`, `User $actor`(申請者本人)
4. **戻り値**: 更新後の`ExpenseReport`
5. **事前条件**: Controllerが`Gate::authorize('resubmit', $expenseReport)`によりオーナー一致・`rejected`状態であることを確認済み。Serviceはこの認可判定を信頼し、状態の再確認は行わない。
6. **処理手順**:
   1. DBトランザクションを開始する
   2. `expense_reports.status`を`submitted`に、`rejection_reason`を`null`に更新する
   3. `approval_histories`へ1行追加する(9章参照)
   4. トランザクションを確定する
   5. 更新後の`ExpenseReport`を返す
7. **更新対象テーブル**: `expense_reports`, `approval_histories`
8. **DBトランザクションの要否**: 必要(status変更・rejection_reasonクリア・履歴追加を1単位として扱うため)。
9. **領収書ファイルの処理**: なし(resubmitはファイルを扱わない。画像の差し替えは事前の`update`で完了している)。
10. **approval_historiesへの記録内容**: 9章参照。再提出は`action`=`submitted`のまま記録し、`from_status`=`rejected`によって初回提出(`from_status`=`draft`)と区別する既存方針(`04_er_diagram.md`)を維持する。
11. **rejection_reasonの更新規則**: `null`にクリアする(過去の却下理由は`approval_histories.comment`側にのみ残る)。
12. **処理失敗時の扱い**: DB更新が失敗した場合は例外を伝播させ、トランザクションはロールバックされる(通常の`QueryException`等としてLaravel標準の例外処理に委ねる)。
13. **Serviceでは行わない処理**: 認可判定、入力値の形式検証、redirect/viewの生成。

### approve

1. **目的**: 提出済みの申請を承認する(F-14)。
2. **呼び出し元Controller**: `Admin\ExpenseReportController@approve`
3. **引数**: `ExpenseReport $expenseReport`, `User $actor`(管理者)
4. **戻り値**: 更新後の`ExpenseReport`
5. **事前条件**: Controllerが`Gate::authorize('approve', $expenseReport)`により`submitted`状態であることを確認済み。Serviceはこの認可判定を信頼し、状態の再確認は行わない。
6. **処理手順**:
   1. DBトランザクションを開始する
   2. `expense_reports.status`を`approved`に更新する
   3. `approval_histories`へ1行追加する(9章参照)
   4. トランザクションを確定する
   5. 更新後の`ExpenseReport`を返す
7. **更新対象テーブル**: `expense_reports`, `approval_histories`
8. **DBトランザクションの要否**: 必要。
9. **領収書ファイルの処理**: なし。
10. **approval_historiesへの記録内容**: 9章参照。
11. **rejection_reasonの更新規則**: 変更しない(承認時に既存の`rejection_reason`があり得るのは、却下→編集→(再提出せず放置)というシナリオはなく、承認は`submitted`からのみ到達するため通常は`null`。念のため変更しない方針とする)。
12. **処理失敗時の扱い**: DB更新が失敗した場合は例外を伝播させ、トランザクションはロールバックされる(通常の`QueryException`等としてLaravel標準の例外処理に委ねる)。
13. **Serviceでは行わない処理**: 認可判定(`submitted`状態かどうかの判定はPolicyの責務)、入力値の形式検証、redirect/viewの生成。

### reject

1. **目的**: 提出済みの申請を却下する(F-15)。
2. **呼び出し元Controller**: `Admin\ExpenseReportController@reject`
3. **引数**: `ExpenseReport $expenseReport`, `User $actor`(管理者), `?string $comment`(却下理由、任意)
4. **戻り値**: 更新後の`ExpenseReport`
5. **事前条件**: `RejectExpenseReportRequest::authorize()`が`ExpenseReportPolicy@reject`へ委譲した認可判定(`submitted`状態であること)を通過済み。`comment`は`RejectExpenseReportRequest`でバリデーション済み。Serviceはこの認可判定を信頼し、状態の再確認は行わない。
6. **処理手順**:
   1. DBトランザクションを開始する
   2. `expense_reports.status`を`rejected`に、`rejection_reason`を`$comment`(nullの場合は`null`のまま)に更新する
   3. `approval_histories`へ1行追加する(9章参照。`comment`には`$comment`をそのまま設定する)
   4. トランザクションを確定する
   5. 更新後の`ExpenseReport`を返す
7. **更新対象テーブル**: `expense_reports`, `approval_histories`
8. **DBトランザクションの要否**: 必要(status変更・rejection_reason更新・履歴追加を1単位として扱うため)。
9. **領収書ファイルの処理**: なし。
10. **approval_historiesへの記録内容**: 9章参照。
11. **rejection_reasonの更新規則**: `$comment`と同じ値を設定する。`$comment`が`null`(却下理由未入力)の場合は`rejection_reason`も`null`のまま保存し、空文字列への変換等は行わない。過去の却下理由は本メソッドでは扱わず、`approval_histories.comment`側の該当履歴行にのみ残る。
12. **処理失敗時の扱い**: DB更新が失敗した場合は例外を伝播させ、トランザクションはロールバックされる(通常の`QueryException`等としてLaravel標準の例外処理に委ねる)。
13. **Serviceでは行わない処理**: 認可判定、`comment`の形式検証(文字数上限: 最大1000文字。FormRequestの責務)、redirect/viewの生成。

## 7. トランザクション方針

| メソッド | DBトランザクション | 理由 |
|---|---|---|
| create | 不要 | `expense_reports`への単一INSERTのみで、DB操作自体が単一ステートメントとして原子性を持つため |
| update | 不要 | `expense_reports`への単一UPDATEのみで、DB操作自体が単一ステートメントとして原子性を持つため |
| delete | 不要 | `expense_reports`からの単一DELETEのみで、DB操作自体が単一ステートメントとして原子性を持つため |
| submit | 必要 | `expense_reports.status`の変更と`approval_histories`への追記を1単位として扱う必要があるため |
| resubmit | 必要 | `expense_reports.status`/`rejection_reason`の変更と`approval_histories`への追記を1単位として扱う必要があるため |
| approve | 必要 | `expense_reports.status`の変更と`approval_histories`への追記を1単位として扱う必要があるため |
| reject | 必要 | `expense_reports.status`/`rejection_reason`の変更と`approval_histories`への追記を1単位として扱う必要があるため |

DBトランザクションは、DBに対する複数の更新をアトミックに保つための仕組みであり、ファイルシステムへの操作(領収書画像の保存・削除)を保護することはできない。そのため、`create`/`update`/`delete`におけるファイル操作の安全性は、DBトランザクションではなく「操作の順序」によって担保する(8章参照)。

## 8. 領収書ファイル管理方針

ファイル保存・削除処理は`ExpenseReportService`内のprivateメソッド(例: `storeReceiptImage()`, `replaceReceiptImage()`, `deleteReceiptImageFile()`)として整理し、別Serviceへは分割しない。現時点でファイル操作の複雑さは「保存」「削除」の2種類のみであり、独立したクラスに分けるほどの責務の複雑さはないと判断したためである。将来、画像のリサイズ・複数ディスク対応・非同期化等が必要になり責務が明らかに複雑化する場合は、`ReceiptImageService`(仮称)等への分割を代替案として検討する。

### 保存タイミングとDB格納値

- 新規画像の保存は、`create`/`update`いずれもFormRequestのバリデーション通過後、DB書き込みの直前(create)またはDB更新に先立つ差し替え処理の一部(update)として行う。
- DBに保存する値は、ファイルの実体(バイナリ)ではなく、保存先ディスク上の相対パス(文字列、`receipt_image_path`)のみである(`05_table_definition.md`と整合)。
- 保存先ディスク・ディレクトリの具体的な設定(`storage/app/public`配下か等)は本ドキュメントでは確定せず、実装フェーズで決定する(15章)。

### 新規画像と削除指定が同時に送信された場合の優先順位

`UpdateExpenseReportRequest`は、新規画像(`receipt_image`)と既存画像の削除フラグ(`remove_receipt_image`)を同時に受け取る可能性がある。以下の優先順位で判定する。

| 入力の組み合わせ | 判定結果 |
|---|---|
| 新規画像あり | 新規画像への差し替えを優先する(`remove_receipt_image`の値に関わらず、新規画像を保存し、DB更新後に旧画像を削除する) |
| 新規画像なし + `remove_receipt_image = true` | 既存画像を削除する |
| どちらもなし | 既存の`receipt_image_path`を変更しない |

この判定はFormRequestではなくServiceの`update()`メソッド内で行う。

### 更新時に画像を差し替える場合の処理

1. 新しい画像ファイルを、既存ファイルとは別のパスとして保存する
2. `expense_reports.receipt_image_path`を新しいパスでUPDATEする
3. 上記2のDB更新が成功したことを確認した後に、旧ファイルを削除する

新ファイルを保存してからDBを更新し、DB更新の成功を確認してから旧ファイルを削除する、という順序にすることで、途中で失敗した場合も「参照先が存在しないパスがDBに残る」という致命的な不整合を避け、被害を「孤立ファイルが残るだけ」に限定する。

### 画像を削除する場合の処理(添付なしにする)

1. `expense_reports.receipt_image_path`を`null`でUPDATEする
2. UPDATEの成功を確認したうえで、旧ファイルを削除する

### 申請自体を削除する場合のファイル削除

`delete`メソッドでは、`expense_reports`からのDELETEが成功したことを確認したうえで、添付されていた領収書画像ファイルを削除する。DELETE失敗時はファイルを削除しない。

### DB処理とファイル操作のどちらか一方だけが成功した場合のリスク

| 発生パターン | 結果 | 深刻度 |
|---|---|---|
| ファイル保存/削除は成功したが、その後のDB書き込みが失敗 | ストレージに孤立ファイルが残る(DBはどこからも参照しない) | 低(ディスク容量を消費するのみ。データ不整合や表示エラーには繋がらない) |
| DB書き込みが成功したが、その後のファイル削除(旧ファイル・DELETE後の添付ファイル)が失敗 | DBは正しい状態を保つが、ストレージに孤立ファイルが残る | 低(同上) |
| DB書き込みより先にファイル削除を行い、その後DB書き込みが失敗する順序にした場合 | DBが参照しているファイルが存在しない状態になる | 高(画面表示エラー・データ不整合に直結するため、本設計ではこの順序を採用しない) |

### MVPとして採用する失敗時の扱い

- 「DBの整合性(存在しないパスを参照しない)」を最優先し、「ストレージに孤立ファイルが残る可能性」は許容する。具体的には、ファイルの新規作成・差し替え・DB反映を先に完了させてから、不要になった旧ファイルを削除する順序を徹底する。
- 孤立ファイルを検出・削除するクリーンアップ処理(バッチ等)はMVP対象外とする(15章)。
- ファイル保存・削除に失敗した場合は例外をそのまま伝播させ、Service内で独自にリトライ・無視は行わない(12章)。

## 9. approval_historiesの記録方針

| メソッド | actor_id | action | from_status | to_status | comment |
|---|---|---|---|---|---|
| submit | 申請者(`$actor->id`) | `submitted` | `draft` | `submitted` | `null` |
| resubmit | 申請者(`$actor->id`) | `submitted` | `rejected` | `submitted` | `null` |
| approve | 管理者(`$actor->id`) | `approved` | `submitted` | `approved` | `null` |
| reject | 管理者(`$actor->id`) | `rejected` | `submitted` | `rejected` | `$comment`(任意、`null`可) |

再提出(resubmit)は`action`を`submitted`のまま記録し、`from_status`が`rejected`であることによって初回提出(`from_status`=`draft`)と区別する、という`04_er_diagram.md`の既存方針を維持する。`create`/`update`/`delete`は状態遷移を伴わないため、`approval_histories`への記録対象外である(`04_er_diagram.md`5章)。

## 10. rejection_reasonの管理方針

`04_er_diagram.md`2章・5章で確定した方針をそのまま踏襲する。

- `approval_histories.comment`が却下理由の履歴上の正本(source of truth)である。
- `expense_reports.rejection_reason`は、詳細画面表示用に保持する直近値のキャッシュである。
- `reject`実行時: `approval_histories.comment`と同じ値を`rejection_reason`に設定する。`comment`が任意入力で`null`の場合(却下理由が未入力の場合)は、`rejection_reason`も`null`のまま保存する。空文字列への変換や「コメントなし」等の文字列への置き換えは行わない。
- `resubmit`実行時: `rejection_reason`を`null`にクリアする。
- 過去の却下理由(現在表示されていない分)は`rejection_reason`では保持せず、`approval_histories.comment`に記録された各履歴行からのみ参照する。

## 11. Policy・FormRequest・Controller・Modelとの責務分担

`07_controller_design.md`6章の責務分担表と整合させ、Service視点から改めて整理する。

| 層 | Serviceとの関係 |
|---|---|
| Controller | Serviceの呼び出し元。認可済み・検証済みの状態でServiceメソッドを呼び出し、戻り値を使ってredirect/flashを行う。Serviceの内部処理(トランザクション・履歴記録・ファイル操作)には関与しない |
| Policy(`ExpenseReportPolicy`) | 操作可能なユーザー・ステータスかを判定する(オーナー一致、ステータス条件)。この判定はServiceの実行前(Controller直接、またはFormRequestの`authorize()`経由)に完了している |
| FormRequest | 入力値の形式検証を行う。検証済みの配列(`$validated`)をServiceに渡すのみで、Service側で同じ検証を重ねて行わない |
| Service(`ExpenseReportService`) | Policyにより認可済みの操作について、状態変更・履歴追加・トランザクション制御・ファイル操作を実行する。認可・入力検証は行わない |
| Model(`ExpenseReport`, `ApprovalHistory`, `ExpenseCategory`, `User`) | Serviceから呼び出されるEloquentのAPI(INSERT/UPDATE/DELETE、リレーション等のクエリビルダ機能)を提供する。業務ルールの判定はModelに持たせない |

Policyが「操作可能なユーザー・ステータスかどうか」を判定し、Serviceは「その判定結果(認可済みであること)を前提に、状態変更・履歴追加・トランザクション制御を実行する」という役割分担とする。ステータス条件の判定はPolicyにのみ記述し、Service側で同じ条件を重ねて判定しない。

## 12. 例外・エラー処理方針

| ケース | 発生源 | Serviceでの扱い | 最終的な処理 |
|---|---|---|---|
| 認可失敗 | Policy(`Gate::authorize`, FormRequestの`authorize()`) | Serviceでは発生しない・catchしない | `AuthorizationException`によりLaravel標準の403レスポンス |
| バリデーション失敗 | FormRequest | Serviceでは発生しない・catchしない(Serviceは検証済み配列のみを受け取る) | `ValidationException`によりLaravel標準の`back()->withInput()` |
| DB更新失敗(接続断・制約違反等) | Model/Eloquent(`QueryException`等) | Serviceでは独自にcatchせず、そのまま伝播させる。トランザクション中であれば自動的にロールバックされる | Laravel標準の例外処理(500) |
| ファイル保存・削除失敗 | `Illuminate\Support\Facades\Storage`(またはファイルシステム例外) | Serviceでは独自にcatchせず、そのまま伝播させる。DB操作より前に発生した場合はDB書き込みを行わない(各メソッドの「処理失敗時の扱い」参照) | Laravel標準の例外処理(500) |

本ドキュメントでは新規の例外クラスは設けない。Controllerが個別に`try/catch`する例外も現時点で設けず(`07_controller_design.md`の方針を維持)、認可・バリデーション・DB/ファイル失敗のいずれも、Laravel標準の例外処理に委ねる。

## 13. 競合更新への対応方針(将来拡張の検討事項)

本MVPは個人開発・ローカル環境での単一利用を前提とする(`01_requirements.md`13章)。承認と却下がほぼ同時に実行されるといった競合更新は、要件・機能一覧のいずれにも想定シナリオとして挙げられておらず、発生確率も実運用上極めて低いため、本ドキュメントでは排他制御(行ロック・楽観ロック等)を実装しない。`submit`/`resubmit`/`approve`/`reject`は、6章の処理手順の通り、Policyによる事前認可を信頼してそのままDBトランザクション内で状態変更・履歴追加を行う。

将来、複数の管理者が同時に運用する場面が生じ、競合更新が実運用上の課題になった場合は、DBトランザクション内での`lockForUpdate()`による行ロック・ステータス再確認、または楽観ロック用カラムの追加を検討する。

## 14. Serviceで扱わない責務

- 認可条件(オーナー一致・ステータス条件)の判定(→Policy)
- 入力値の形式検証(→FormRequest)
- HTTPリクエスト/レスポンスの直接操作(redirect、flash、view返却)(→Controller)
- 一覧表示・詳細表示・カテゴリマスタ取得等の読み取り専用処理(→Controller・Model)
- HTML・デザイン・画面レイアウト(→Blade)

## 15. 懸念点・確認事項

- 競合更新への排他制御(行ロック・楽観ロック)は今回見送ったが、将来的に管理者が複数人体制になり競合頻度が実運用上問題になった場合の導入基準は、運用開始後に改めて判断する。
- 領収書画像の保存先ディスク・ディレクトリ構成(`storage/app/public`配下か等)は本ドキュメントでは確定せず、実装フェーズで決定する。
- ストレージに残り得る孤立ファイル(DBから参照されなくなった領収書画像)のクリーンアップ運用(定期バッチ等)はMVP対象外とし、必要になれば別途検討する。
- `reject`時に`comment`が`null`(却下理由未入力)だった場合、`rejection_reason`も`null`となる。詳細画面(SC-03/SC-06)側でこれをどう表示するか(空欄のままにするか、「コメントなし」等の文言を出すか)は、View実装フェーズで確認する。
