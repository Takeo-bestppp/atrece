<?php

function output($organization_code){
	global $p_kohi_type_code;

	//*******************************************************************************************
	//* 出力準備。headerセットおよび、バッファリング設定
	//*******************************************************************************************
	ob_start();
	header("Pragma: public");
	header("Cache-Control: public");
	header("Content-Type: application/octet-stream");
	header("Content-Disposition: attachment; filename=RECEIPTS.UKE");

	set_time_limit(0);
	//ini_set('memory_limit', '256M');
	session_start();

	//ＤＢアクセス用
	require_once("./dlib.php");
	require_once("./dlib_multi.php");

	//ログ出力用
	require_once("./common/inc.log.php");

	//社保かのフラグ
	($organization_code == ORGANIZATION_CODE_N) ? $syaho_flg = 0 : $syaho_flg = 1;

	/*
	* 「医療費等助成費（県単公費）レセプトを併用として社保へ請求」というチェック
	* ボックスを作成し、ここにチェックがある場合は手引きp.19には関係なく、公費
	* 負担番号さえあれば、3桁目を「2」として出力できるようにしていただきたいの
	* ですが可能でしょうか？
	*/
	$kentan_kohi_flg = $_GET['kentan_kohi_flg'];

	//パラメータの日付範囲を取得
	$get_this_date = $_GET['StartDate'];
	$get_this_end_date = $_GET['EndDate'];

	//診療報酬請求書の総合計点数
	//$total_tensu = 0;
	$sum_total = 0;

	//***************************************************************
	// DB設定
	//***************************************************************
	$DB_NAME = "DB".$_SESSION["LoginCheckerId"];
	$p_db = new EzDBMulti($DB_NAME);
	$db = new EzDBMulti($DB_NAME);
	$s_db = new EzDBMulti($DB_NAME);
	$bui_db = new EzDBMulti($DB_NAME);
	$tdb = new EzDBMulti($DB_NAME);

	//***************************************************************
	//CSV出力期間を元に処置の詳細情報を一括取得
	//***************************************************************
	//どちらの処置を使用するか配列番号を格納している配列
	//$aaa[日付番号20130403] = 0番データ
	$startDate = strtotime($get_this_date);
	$endDate = strtotime($get_this_end_date);

	//各年月の処置マスタ情報をストック
	$mSyochi = new SyochiMasterTable(new EzDBMulti($DB_NAME));

	//返戻年月日をもとにその当時の処置マスタを問い合わせ
	$split_date = explode('/', $get_this_date);
	$henrei_ym = sprintf("%04d", $split_date[0]).sprintf("%02d", $split_date[1]);
	$henrei_sql = '';
	$p_db->sql(create_sql($get_this_date, $get_this_end_date, $henrei_ym));
	if($p_db->rows > 0){
		for($page=0; $page<$p_db->rows; $page++){
			$henrei_flg = $p_db->get("henrei_flg", $page);								//返戻の患者かどうかのフラグ
			if($henrei_flg == 1){
				$p_jyushin_date = $p_db->get("JYUSHIN_DATE", $page);
				if($startDate > strtotime($p_jyushin_date)){
					$startDate = strtotime($p_jyushin_date);
				}
				$henrei_jyushin_date  = date("Y-m-d",strtotime("-1 day",strtotime("+1 month",strtotime(substr($p_jyushin_date,0,7)."/01"))));
				$mSyochi->prepareSyochiMasterByDate($henrei_jyushin_date);

			}else if($p_db->get("SAISEIKYU", $page) == '1'){
				$p_shinryo_ym = $p_db->get("SHINRYO_YM", $page);

				$henrei_shinryo_ym  = date("Y-m-d",strtotime("-1 day",strtotime("+1 month",strtotime(substr($p_shinryo_ym,0,7)."-01"))));
				$mSyochi->prepareSyochiMasterByDate($henrei_shinryo_ym);
			}
		}
	}

	$mSyochi->prepareSyochiMasterByTerm($get_this_date, $get_this_end_date);
	$dateMaster = $mSyochi->dateMaster;
	$syochiMaster = $mSyochi->syochiMaster;
	unset($mSyochi);


	//***************************************************************
	//医院情報を取得する
	//***************************************************************
	$sql = "select * from M_CLINIC_INFO";
	$db->sql($sql);

	$clinic_pref = $db->get("PREF_NO", 0);
	$clinic_code = $db->get("CLINIC_CODE", 0);
	$clinic_add1 = $db->get("ADD1", 0);
	$clinic_add2 = $db->get("ADD2", 0);
	$clinic_name = $db->get("CLINIC_NAME", 0);
	$clinic_tel  = $db->get("TEL", 0);
	$clinic_person = $db->get("DAIHYO_NAME", 0);

	$ho  = 0;
	$ses = 0;
	$grk = 0;
	$umt = 0;
	$gtr = 0;
	$ik  = 0;
	$zsk = 0;
	$sr  = 0;
	$ssk = 0;
	$sg  = 0;
	$mei = 0;

	//先月届出があればtrueとし、届出の値を入れる →届出日の確認はおこなわないようになりました（#2645）
	$ho  = $db->get("H", 0);		//補管
	$ses = $db->get("SES", 0);		//歯援診
	$grk = $db->get("GRK", 0);		//外来環
	$umt = $db->get("UMT", 0);		//う蝕無痛
	$gtr = $db->get("GTR", 0);		//GTR
	$ik  = $db->get("IK", 0);		//医管
	$zsk = $db->get("ZSK", 0);		//在歯管
	$sr  = $db->get("SR", 0);		//障連
	$ssk = $db->get("SSK", 0);		//手術歯根
	$sg  = $db->get("SG", 0);		//歯技工
	$mei = $db->get("M", 0);		//明細
	//課題 #2641 医院情報画面の届出欄追加 での追加分（TIJ,ZIK,CAD,REH）
	$tij = $db->get("TIJ", 0);
	$zik = $db->get("ZIK", 0);
	$cad = $db->get("CAD", 0);
	$reh = $db->get("REH", 0);
	//タスク #3931 かかりつけの追加(KKS)
	$kks = $db->get("KKS", 0);

	$facility_criteria_code_arr = array();
	$facility_criteria_code_arr[] = $ho;
	$facility_criteria_code_arr[] = $ses;
	$facility_criteria_code_arr[] = $grk;
	$facility_criteria_code_arr[] = $umt;
	$facility_criteria_code_arr[] = $gtr;

	$facility_criteria_code_arr[] = $ik;
	$facility_criteria_code_arr[] = $zsk;
	$facility_criteria_code_arr[] = $sr;
	$facility_criteria_code_arr[] = $ssk;
	$facility_criteria_code_arr[] = $sg;
	$facility_criteria_code_arr[] = $mei;
	$facility_criteria_code_arr[] = $tij;
	$facility_criteria_code_arr[] = $zik;
	$facility_criteria_code_arr[] = $cad;
	$facility_criteria_code_arr[] = $reh;
	$facility_criteria_code_arr[] = $kks;

	//もし補管届け出が出ていない医院なら、「未届出減算AM004」のフラグを立てておくこと
	$no_h_flg = 0;
	if($ho == 0) $no_h_flg = 1;

	//************************************************************************************
	//受付情報レコード
	//************************************************************************************
	$uk_arr = array();
	$uk_arr[] = UK_CODE;
	$uk_arr[] = $organization_code;
	$uk_arr[] = sprintf("%02d", $clinic_pref);
	$uk_arr[] = SCORE_CODE;
	$uk_arr[] = mb_substr($clinic_code, 0, 7, SYS_ENCODE);
	$uk_arr[] = '';
	$uk_arr[] = mb_substr($clinic_name, 0, 20, SYS_ENCODE);
	$uk_arr[] = change_era(date('Y/m/d'));			//請求年月日
	$uk_arr[] = create_facility_criteria_code($facility_criteria_code_arr);	//届出
	$uk_arr[] = '00';

	echo create_csv($uk_arr);	//受付情報レコード出力

	//返戻年月日整形作業
	$split_date = explode('/', $get_this_date);
	$henrei_ym = sprintf("%04d", $split_date[0]).sprintf("%02d", $split_date[1]);

	//レセプトレコード数記録用
	$rece_total = 0;

	$p_db->sql(create_sql($get_this_date, $get_this_end_date, $henrei_ym));
	if($p_db->rows > 0){

		//その患者のＣＳＶレコード接頭辞的ものを格納するための配列
		$csv_prefix = array();

		//その患者のHSレコードを格納するための配列
		$csv_hs_array = array();

		//診療識別コード順に並べ替えるための配列
		$syoche_sort_array = array();

		//返戻時に履歴データを格納するための配列
		$csv_rireki = array();

		//患者総数
		$p_num = $p_db->rows;
		for($page=0; $page<$p_num; $page++){

			//患者別ＣＳＶ出力内容　一時保管場所
			$IRcsv = '';
			$REcsv = '';
			$HOcsv = '';
			$csv = '';

			//REレコード　レセプト特記事項欄に40を記入するフラグ
			$flg_40 = false;

			//2658「災１」のコメントの算定があるか
			$sai1_flg = false;

			$houmon = 0;
			$henrei_flg = 0;
			$henrei_flg = $p_db->get("henrei_flg", $page);								//返戻の患者かどうかのフラグ

			//未来院請求対応
			$miraiin_flg = 0;
			$miraiin_flg = $p_db->get("miraiin_flg", $page);

			$patient_id = $p_db->get("ID", $page);										//患者ID
			$roujin_taisyoku = $p_db->get("ROUJIN_TAISYOKU", $page);
			$hokenja_no = trim($p_db->get("HOKENJA_NO", $page));						//保険者番号
			$kohi_futan_no1 = $p_db->get("KOHI_FUTAN_NO1", $page);						//公費負担者番号1
			if(trim($kohi_futan_no1) == '')$kohi_futan_no1 = '';
			$kohi_jyukyu_no1 = trim($p_db->get("KOHI_JYUKYU_NO1", $page));				//公費受給者番号1
			$kohi_futan_no2 = $p_db->get("KOHI_FUTAN_NO2", $page);						//公費負担者番号2
			if(trim($kohi_futan_no2) == '')$kohi_futan_no2 = '';
			$kohi_jyukyu_no2 = trim($p_db->get("KOHI_JYUKYU_NO2", $page));				//公費受給者番号2
			$futan_wariai = 10 - (int)$p_db->get("FUTAN_WARIAI", $page);				//負担割合	表記上、患者の負担割合ではなく、保険者の負担割合なので10からマイナス
			$zokugara = $p_db->get("ZOKUGARA", $page);									//続柄			本人・家族
			$birth = $p_db->get("BIRTH", $page);										//生年月日
			$age = calc_age($birth, $get_this_date);									//年齢計算
			$age_now = calc_age_now($birth, $get_this_date);							//年齢計算（システム日付を基準に）
			//$patient_name = $p_db->get("PATIENT_NAME", $page);						//旧患者名
			$patient_family_name = trim($p_db->get("PATIENT_FAMILY_NAME", $page));		//患者　姓
			$patient_first_name = trim($p_db->get("PATIENT_FIRST_NAME", $page));		//患者　名
			$konnan = $p_db->get("KONNAN_FLG", $page);									//困難フラグ（困難者は50/100になる）
			$hoken_kigou = $p_db->get("HIHOKEN_KIGOU", $page);							//被保険者記号
			$hoken_no = $p_db->get("HIHOKENJA_NO", $page);								//被保険者番号
			$kouhi_flg = $p_db->get("KOHI_UMU", $page);									//公費ありフラグ
			$sex = $p_db->get("SEX", $page);											//性別
			$tmp_jyushin_date = $p_db->get("JYUSHIN_DATE", $page);						//受診日(年月のみ用)
			$kohi_futankin = $p_db->get("KOHI_FUTANKIN", $page);						//公費負担金
			$kogaku_getsuji_max = $p_db->get("KOGAKU_GETSUJI_MAX", $page);				//高額療養費自己負担月額上限
			$kogaku_kubun = $p_db->get("KOGAKU_KUBUN", $page);							//高額療養費制度区分
			$syokumu_jiyuu = $p_db->get("SYOKUMU_JIYUU", $page);						//職務事由
			if($syokumu_jiyuu == 0) $syokumu_jiyuu = '';
			$saiseikyu = $p_db->get("SAISEIKYU", $page);								//再請求フラグ
			$rireki = $p_db->get("RIREKI", $page);										//履歴管理情報
			$shinryo_ym = $p_db->get("SHINRYO_YM", $page);								//診療年月
			$search_number = $p_db->get("SEARCH_NUMBER", $page);						//検索番号
			if($saiseikyu == '1'){
				$rireki = mb_convert_encoding($rireki, CSV_ENCODE, SYS_ENCODE);
				$csv_rireki[$page + 1][$patient_id] = $rireki;
			}else{
				$csv_rireki[$page + 1][$patient_id] = '';
			}


			//診療開始日は返戻時には、返戻の日付から見た診療開始日にすること 2011/04/18
			if($henrei_flg == 1) {
				$jushin_end_date = date("Y/m/d", strtotime("-1 day", strtotime("+1 month", strtotime(substr($p_db->get("JYUSHIN_DATE", $page), 0, 7) . "/01"))));
			}else if($saiseikyu == '1'){
				$jushin_end_date = date("Y/m/d", strtotime("-1 day", strtotime("+1 month", strtotime(substr($p_db->get("SHINRYO_YM", $page), 0, 7) . "-01"))));
			}else{
				$jushin_end_date = $get_this_end_date;
			}

			//レセプトデータとして記録する「診療開始日」は、
			//レセプト集計対象日のＴＯより、最も日付の遅い「初診算定日」としてください。
			$sql  = "SELECT k.JYUSHIN_DATE FROM T_KARTE as k INNER JOIN T_PATIENT as p ON k.PATIENT_ID = p.ID ";
			$sql .= " WHERE k.DEL_FLG = 0 AND p.DEL_FLG = 0 AND k.syoshin_flg = 1 ";
			$sql .= " AND k.JYUSHIN_DATE <=  '".$jushin_end_date."' ";
			$sql .= " AND p.ID = ".$patient_id;
			$sql .= " ORDER BY k.JYUSHIN_DATE DESC LIMIT 0,1 ";

			$tdb->sql($sql);
			$syoshin_date = $tdb->get("JYUSHIN_DATE", 0);
			if($syoshin_date == '') $syoshin_date = $p_db->get("SYOSHIN_DATE", $page);

			//負担区分(SSレコード用)
			$futan_type = 1;
			//公費なしの場合「1：1者（医保）」
			if($kouhi_flg == 0){
				$futan_type = 1;

			//保険者番号あり、かつ公費負担者番号１、２に入力ありの場合
			//　(追記：HOレコードが有り、かつKOレコードが2つ存在した場合といえるためこのままイキ　2013/01/04)
			}else if($hokenja_no != '' && $kohi_futan_no1 != '' && $kohi_futan_no2 != ''){
				$futan_type = 4;

				//もし社保かつ該当なし公費負担者コードなら負担区分を2に変更
				if($syaho_flg == 1 && $p_kohi_type_code[mb_substr($kohi_futan_no1,0,2)] != '1') $futan_type = 2;

				//でも、県単公費フラグがチェックされている、かつ、社保、かつ、公費負担者番号がある場合は4に戻す
				if($kentan_kohi_flg == 1 && $syaho_flg == 1 && (strlen($kohi_futan_no1) > 0)) $futan_type = 4;

			//保険者番号あり、かつ公費負担者番号１に入力ありの場合
			}else if($hokenja_no != '' && $kohi_futan_no1 != ''){
				$futan_type = 2;

				//もし社保かつ該当なし公費負担者コードなら負担区分を1に変更
				if($syaho_flg == 1 && $p_kohi_type_code[mb_substr($kohi_futan_no1,0,2)] != '1') $futan_type = 1;

				//でも、県単公費フラグがチェックされている、かつ、社保、かつ、公費負担者番号がある場合は2に戻す
				if($kentan_kohi_flg == 1 && $syaho_flg == 1 && (strlen($kohi_futan_no1) > 0)) $futan_type = 2;

			//HOレコードが無く、かつKOレコードが2つ存在した場合　すべてのSSレコードの3項目「負担区分コード」は「7」となる。
			}else if($hokenja_no == '' && $kouhi_flg == 1 && $kohi_futan_no1 != '' && $kohi_futan_no2 != ''){
				$futan_type = 7;

			//保険者番号がなく、公費チェックあり。（公費単独）
			}else if($hokenja_no == '' && $kouhi_flg == 1){
				$futan_type = 5;
			}

			//************************************************************************************
			//医療機関情報レコード
			//************************************************************************************
			$ir_arr = array();
			$ir_arr[] = IR_CODE;
			$ir_arr[] = $organization_code;
			$ir_arr[] = sprintf("%02d", $clinic_pref);
			$ir_arr[] = SCORE_CODE;
			$ir_arr[] = mb_substr($clinic_code, 0, 7, SYS_ENCODE);
			$ir_arr[] = '';
			$ir_arr[] = change_era(date('Y/m/d'));
			$ir_arr[] = $clinic_tel;
			$ir_arr[] = create_facility_criteria_code($facility_criteria_code_arr);	//届出
			$IRcsv .= create_csv($ir_arr);


			//************************************************************************************
			//レセプト共通レコード
			//************************************************************************************

			//フィールド１　国保の場合＞1国　社保＞1社　公費の場合＞2公費  後期高齢者の場合＞3後期　退職者の場合＞4退職
			$re_code = '3';		//歯科だから3
			$flg1 = 0;
			$flg2 = 0;
			$re_code2 = 0;
			$re_code3 = 0;

			//2桁目
			if($roujin_taisyoku == 1 || mb_substr($hokenja_no,0,2) == '67'){
				//退職
				$re_code .= '4';
				$re_code2 = 4;
				//PDF_show_xy($p, mb_convert_encoding("4退職", "EUC-JP", "UTF-8"), $line_x+462, $line_y+804);
			}else if(strlen($hokenja_no) == 8 && mb_substr($hokenja_no,0,2) == "39"){
				//老人７、９割
				$re_code .= '3';
				$re_code2 = 3;
				//PDF_show_xy($p, mb_convert_encoding("3後期", "EUC-JP", "UTF-8"), $line_x+462, $line_y+804);
			}else{
				//国保だと決してここには入らない
				if ((strlen($kohi_futan_no1)> 0 || strlen($kohi_jyukyu_no1) > 0) && strlen(trim($hokenja_no))==0){
					//PDF_show_xy($p, mb_convert_encoding("2公費", "EUC-JP", "UTF-8"), $line_x+462, $line_y+804); //公費
					$re_code .= '2';
					$re_code2 = 2;
					$flg1 = 1;
				}else{
					$re_code .= '1';
					$re_code2 = 1;
				}
			}

			//3桁目
			$resReceiptType = checkReceiptType($hokenja_no, $kouhi_flg, $futan_type, $kohi_futan_no1, $kohi_futan_no2);
			if($resReceiptType == 0){
				$re_code3 = 1;
			}else if($resReceiptType == 1){
				$re_code3 = 3;
			}else if($resReceiptType == 2){
				$re_code3 = 2;

			/*
			※以前の仕様で、公費負担にチェックがあれば、REレコードの3項目「レセプト種別」の3桁目は「2」となる。
			となっておりますが、今回の仕様のほうを優先し、今回の仕様に当てはまらない場合には、
			前回の仕様で処理されるようにしてください。      }else if(...){以下が前回の仕様 */
			}else if((strlen($kohi_futan_no1) > 0 || strlen($kohi_jyukyu_no1) > 0) && strlen($hokenja_no) > 0){//併用
				//PDF_show_xy($p, mb_convert_encoding("2併用", "EUC-JP", "UTF-8"), $line_x+497, $line_y+804);
				//$re_code .= '2';
				$re_code3 = 2;

				/*・REレコードのレセプト種別について
				　　　・前述の、社保で公費負担者番号の先頭2桁が手引きp.19に記載ない場合には
				　　　　レセプト種別の3桁目は「2」ではなく「1」となる
				*/
				if($syaho_flg == 1 && $p_kohi_type_code[mb_substr($kohi_futan_no1,0,2)] != '1'){
					//$re_code .= '1';
					$re_code3 = 1;
					$flg2 = 1;
				}

				/*
				* 「医療費等助成費（県単公費）レセプトを併用として社保へ請求」というチェック
				* ボックスを作成し、ここにチェックがある場合は手引きp.19には関係なく、公費
				* 負担番号さえあれば、3桁目を「2」として出力できるようにしていただきたいの
				* ですが可能でしょうか？
				*/
				//県単公費フラグがチェックされている、かつ、社保、かつ、公費負担者番号がある
				if($kentan_kohi_flg == 1 && $syaho_flg == 1 && (strlen($kohi_futan_no1) > 0)){
					//$re_code .= '2';
					$re_code3 = 2;
					$flg2 = 0;
				}

			}else{//単独
				$re_code3 = 1;
				$flg2 = 1;
				//PDF_show_xy($p, mb_convert_encoding("1単独", "EUC-JP", "UTF-8"), $line_x+497, $line_y+804);
			}
			$re_code .= $re_code3;


			//診療報酬請求書レコード準備
			//レセプト種別3桁目の値がそのまま加算されるレセプトレコード数との考え
			$rece_total += $re_code3;


			//4桁目
			if($age >= 70 || (strlen($hokenja_no) == 8 && mb_substr($hokenja_no,0,2) == "39")){
				if((strlen($kohi_futan_no1) > 0 || strlen($kohi_jyukyu_no1) > 0) && strlen($hokenja_no) == 0){
					$re_code .= '2';
				}else if($futan_wariai == 7){
					$re_code .= '0';
				}else{
					$re_code .= '8';
				}
			}else{
				//本人・三歳未満・家族の○
				switch($zokugara){
				case 1:
					$re_code .= '2';
					break;
				case 2:
					//年齢を割り出し、３歳未満だったら三外に○、3歳以上は家外に○
					//20080808　未就学児の場合4六外とするように変更
					//未就学を考慮する 就学予定日を計算する　2008/08/13
                    /*
					$birthday_age6 = "";
					$birthday_age6 = date("Y/m/d",strtotime("+6 year",strtotime($birth)));   //満6歳の誕生日

					//誕生日が4月1日より前かを判断し、就学年月日を算出 #3431で再修正（4/2→4/1）
					$syugaku = "";
					if(strtotime($birth) <= strtotime(substr($birth,0,4)."/04/01")){  //4月1日生まれは早生まれ
						$syugaku = substr($birthday_age6,0,4)."/04/01";
					}else{
						$syugaku = date("Y",strtotime("+1 year",strtotime($birthday_age6))) ."/04/01";
					}
					//就学日が、出力開始日以下なら未就学と判断
					if(strtotime($syugaku) > strtotime($get_this_date)){
						$re_code .= '4';
					} else {
						$re_code .= '6';
					}
                    */
                    if(isSyugaku($birth, $get_this_date) === true){
                        $re_code .= '6';
                    }else{
                        $re_code .= '4';
                    }
					break;
				}
			}


			//患者名の編集　一度半角の名前として結合しておく
			$patient_name = $patient_family_name." ".$patient_first_name;

			$len = strlen($patient_family_name);
			$mblen = mb_strlen($patient_family_name, SYS_ENCODE);

			//もし患者名が全角いりなら全部全角に変換
			if($len !== $mblen){
				$patient_name = mb_convert_kana($patient_family_name, "AKNRSV", SYS_ENCODE)."　".mb_convert_kana($patient_first_name, "AKNRSV", SYS_ENCODE);
			}


			/*
			* この医院に今月の日付順にレセプトがいくつあるか取得。
			* レセプト毎に医療機関情報から出力
			*/
			$sql  = "select * from T_KARTE WHERE DEL_FLG = 0 AND PATIENT_ID = ".$patient_id;
			if($saiseikyu == '1'){
				$sql .= " AND henrei_flg = 0 and JYUSHIN_DATE LIKE '" . date("Y/m/", strtotime($shinryo_ym)) . "%' ";
			}else if($henrei_flg == '0'){
				$sql .= " AND henrei_flg = 0 and JYUSHIN_DATE >= '".$get_this_date."' and JYUSHIN_DATE <= '".$get_this_end_date."' ";
			}else{
				$sql .= " AND henrei_flg = 1 AND henrei_ym = ".$henrei_ym;
			}
			$sql .= " ORDER BY JYUSHIN_DATE ";
			$db->sql($sql);
			$jitu_nissu = $db->rows;													//診療実日数

			$illegal_karte = array();
			$tmp_db = new EzDBMulti($DB_NAME);
			for($i=0; $i<$db->rows; $i++){
				//処置が存在しない不正なカルテデータが存在していないかチェックを行う
				$sql  = "select count(*) as HIT from T_KARTE_SYOCHI WHERE KARTE_ID = ".$db->get("ID", $i);
				$tmp_db->sql($sql);
				if($tmp_db->get("HIT", 0) == 0){
					$jitu_nissu--;
					$illegal_karte[] = $db->get("ID", $i);
				}

				//衛生士単独訪問のカルテの場合、実日数から減算する
				if($db->get("eiseishi_hou_flg", $i) == 1){
					$jitu_nissu--;
				}
			}

            $outcome_code = '';    //転帰区分
			for($i=0; $i<$db->rows; $i++){
				//訪問診療の有無をチェック
				$karteId = $db->get("ID", $i);
				if(array_search($karteId, $illegal_karte) === FALSE){

					//処置を取得
					$sql  = "SELECT DISTINCT m.SYOCHI_GROUP_ID, m.ID FROM T_KARTE as k INNER JOIN T_KARTE_SYOCHI as s on k.ID = s.KARTE_ID ";
					$sql .= " INNER JOIN M_SYOCHI_MASTER as m ON s.SYOCHI_ID = m.ID ";
					$sql .= " AND m.DATA_KIGEN_S <= k.JYUSHIN_DATE AND m.DATA_KIGEN_E >= k.JYUSHIN_DATE ";
					$sql .= " WHERE k.DEL_FLG = 0 AND m.SYOCHI_GROUP_ID=800 AND k.ID = ".$karteId;

					$s_db->sql($sql);
					$syochi_group_num = $s_db->rows;
					if($syochi_group_num > 0) $houmon = 1;
/*
					for($j=0; $j<$syochi_group_num; $j++){
						if($s_db->get("SYOCHI_GROUP_ID", $j) == 800){
							$houmon = 1;
						}
					}
*/

					//カルテの全処置を取得
					$sql  = "SELECT T_KARTE_SYOCHI.* ";
					$sql .= "FROM T_KARTE INNER JOIN T_KARTE_SYOCHI ";
					$sql .= "ON T_KARTE.ID = T_KARTE_SYOCHI.KARTE_ID ";
					$sql .= "WHERE T_KARTE.ID = ".$karteId;
					$s_db->sql($sql);
					for($j=0; $j<$s_db->rows; $j++){

                        //TODO #3118
                        /*
                        #3118
                        １．処置ID-2736～2739が算定された場合、対応する転帰区分をCSVおよび紙レセプトに出力する（詳細は上記"説明"を参照）
                                処置ID：2736　転帰区分記録（治ゆ、死亡、中止以外）：1
                                処置ID：2737　転帰区分記録（治ゆ）：2
                                処置ID：2738　転帰区分記録（死亡）：3
                                処置ID：2739　転帰区分記録（中止（転医））：4
                        ２．上記１に該当しない場合、#2620の修正どおり、以下のように出力される（今回、紙レセプトも同様に処理追加する）
                        　　・摘要欄コメント(処置ID-2556)「治ゆ」を採用した場合は、
                        　　　REレコードの11項目に(「1」や「4」に代わって）「2」が記録される。
                        　　・摘要欄コメント(処置ID-2557)「死亡」を採用した場合は、
                        　　　REレコードの11項目に(「1」や「4」に代わって）「3」が記録される。
                        ３．上記１～２に該当しない場合、かつ未来院請求時には「中止（転医）：4」が記録される

                        #2620
                        ・コメント(処置ID-2556)
                        ・コメント(処置ID-2557)
                        の両方が算定されていた場合は、「死亡」を優先し、転帰区分を3とするようにしています。
                         */
                        $syochi_id = $s_db->get("SYOCHI_ID", $j);
                        switch($syochi_id){
                            case 2736:
                                $outcome_code = '1';
                                break;
                            case 2737:
                                $outcome_code = '2';
                                break;
                            case 2738:
                                $outcome_code = '3';
                                break;
                            case 2739:
                                $outcome_code = '4';
                                break;
                            case 2557:
                                $outcome_code = '3';
                                break;
                            case 2556:
                                $outcome_code = '2';
                                break;
                           default:
                               break;
                        }
					}

				}
			}


			//受診日(年月のみ用)
			$era_jyushin_date = change_era($tmp_jyushin_date);

			if($miraiin_flg == 1){
				//未来院の場合、実日数をリセット
				$jitu_nissu = 0;
				//未来院の場合、今月のカルテは１つ限定なので0レコード目のカルテＩＤを取得
				$k_id = $db->get("ID", 0);	//カルテID
				if(array_search($k_id, $illegal_karte) === FALSE){

					//処置を取得
					$sql  = "SELECT * FROM T_KARTE_SYOCHI WHERE KARTE_ID = ".$k_id;
					$tmp_db = new EzDBMulti($DB_NAME);
					$tmp_db->sql($sql);

					$s_num = $tmp_db->rows;

					for($i=0; $i<$s_num; $i++){
						$s_id = $tmp_db->get("SYOCHI_ID", $i);	//処置ID
						if($s_id == 1682) $syochi_name1682 = $tmp_db->get("SYOCHI_NAME", $i);
					}

					//$tmp = preg_replace("/^[^0-9]*([0-9]{1,2})年([0-9]{1,2}).*$/u", "$1,$2", $syochi_name1682);
					$tmp = preg_replace("/^[^0-9０-９]*([0-9０-９]+)[^0-9０-９]*([0-9０-９]+)[^0-9０-９]*$/u", "$1,$2", $syochi_name1682);
					$tmp_str = explode(',', $tmp);

					$era_jyushin_date = ERA_H.sprintf("%02d", $tmp_str[0]).sprintf("%02d", $tmp_str[1]);
				}
			}

			$re_arr = array();
			$re_arr[] = RE_CODE;
			$re_arr[] = $page + 1;			//レセプト数
			$re_arr[] = $re_code;			//レセプト種別コード
			$re_arr[] = $era_jyushin_date;	//年月のみ用
			$re_arr[] = $patient_name;
			$re_arr[] = $sex + 1;			//アットレセは男0女1　CSVは男1女2
			$re_arr[] = change_era_ymd($birth);

			//給付割合
			//社保の場合
			if($syaho_flg == 1){
				$re_arr[] = '';				//空欄固定　QA6にて回答
			//国保の場合
			}else{
				/*
				１．国保でHOの保険者番号の上二桁が39（後期高齢者）の場合以外は「給付割合」に記録する
				２．「給付割合」は百分率で3桁で記録する。「給付割合」＝100　－　患者負担割合（％）
				　　例）患者負担が3割の場合　「給付割合」＝100　－　30％＝70　CSVには070と記載する。
                ３－１．レセプト種別が3118,3128および3138の場合だけは、常に080とする。
                //３－２．出力月の時点で未就学の場合は'080'とする。 //#3934で、この判定は削除されました
                ３－３．出力時点の実年齢が70歳以上、かつ、出力月の1日時点で70歳未満の場合は、'070'とする。
                　　（実年齢を判定に加えているのは、未就学が070にならないようにするためです）
                ３－４．上記１～３以外は、患者詳細画面の負担割合を表示する。
				*/
				if(mb_substr($hokenja_no,0,2) != "39"){
					if($re_code == '3118' || $re_code == '3128' || $re_code == '3138'){
						$re_arr[] = '080';
					}else{
                        //#3887 レセプトのREレコード(8)給付割合の変更
                        if($age_now >= 70 && $age < 70) {
                            $re_arr[] = '070';
                        }else {
                            $re_arr[] = sprintf("%03d", ($futan_wariai * 10));
                        }
					}
				}else{
					$re_arr[] = '';
				}
			}
			$re_arr[] = '';
			$re_arr[] = change_era_ymd($syoshin_date);

            //11．転帰区分（入院外）
			if($outcome_code == '') {
                if ($miraiin_flg > 0){
                    $outcome_code = '4';
                }else{
                    $outcome_code = '1';
                }
            }
			$re_arr[] = $outcome_code;

			//12.
			$re_arr[] = '';

            //13.区分コード（現時点では情報不足のため、後工程で値をセットする）
            $re_arr[] = '';

            //14.レセプト特記事項（現時点では情報不足のため、後工程で値をセットする）
			$tokki_jikou = '';

			//５歳未満か困難者の場合か訪問診療あり(SYOCHI_GROUP_ID = 800か)　なら50/100
			if($age < 6 || $konnan == 1 || $houmon == 1){
				$tokki_jikou .= '';

			//公費で単独なら特記事項に「後保」の表示をする
			}else if($flg1 == 1 && $flg2 == 1 && $age >= 75){
				$tokki_jikou .= '04';
			}else{
				$tokki_jikou .= '';
			}


			$re_arr[] = $tokki_jikou;	//特記事項を設定

			//15.
			$re_arr[] = '';
			//16.カルテ番号等
			$re_arr[] = $patient_id;
			//17.
			$re_arr[] = '';
			//18.
			$re_arr[] = '';

			//19.未来院請求
			$outcome_code = '';
			if($miraiin_flg > 0) $outcome_code = '01';
			$re_arr[] = $outcome_code;

			//20.検索番号
			$re_arr[] = ($saiseikyu == '1') ? $search_number : '';
			$re_arr[] = '';
			$re_arr[] = $henrei_flg;
			$re_arr[] = '';
			$re_arr[] = '';
			$re_arr[] = '';

// 2018-04-20 OHTA ADD
			$re_arr[] = '';
			$re_arr[] = '';
// 2018-04-20 OHTA END

			$REcsv .= create_csv($re_arr);


			//合計点数を取得
			$sql  = "select ";
			$sql .= " SUM(GOKEI) as MONTH_GOKEI";
            $sql .= ", SUM(ROUND(GOKEI*FUTAN_WARIAI/10)*10) AS SUM_FUTANKIN_WITHOUT_KOHI";
            $sql .= ", SUM(ROUND(TMP_FUTANKIN/10)*10) AS SUM_TMP_FUTANKIN";
			$sql .= ", SUM(ROUND(KOHI_FUTANKIN/10)*10) AS SUM_KOHI_FUTANKIN";
            $sql .= ", SUM(FUTANKIN) AS SUM_FUTANKIN ";
			$sql .= "from T_KARTE WHERE DEL_FLG = 0 AND PATIENT_ID = ".$patient_id;

			if($saiseikyu == '1'){
				$sql .= " AND henrei_flg = 0 and JYUSHIN_DATE LIKE '" . date("Y/m/", strtotime($shinryo_ym)) . "%' ";
			}else if($henrei_flg == '0'){
				$sql .= " AND henrei_flg = 0 and JYUSHIN_DATE >= '".$get_this_date."' and JYUSHIN_DATE <= '".$get_this_end_date."' ";
			}else{
				$sql .= " AND henrei_flg = 1 AND henrei_ym = ".$henrei_ym;
			}
			if(count($illegal_karte) > 0){
				$sql .= " AND ID NOT IN (".implode(',', $illegal_karte).") ";
			}

			$sql .= " ORDER BY JYUSHIN_DATE ";
			$tmp_db = new EzDBMulti($DB_NAME);
			$tmp_db->sql($sql);
			$gokei = $tmp_db->get("MONTH_GOKEI", 0);		//合計
			$sum_total += $gokei;
			$sum_tmp_futankin = $tmp_db->get("SUM_TMP_FUTANKIN", 0);		//公費計算後の負担金額の月次合計（公費計算がない人は保険点数による負担金額のまま）
			$sum_kohi_futankin = $tmp_db->get("SUM_KOHI_FUTANKIN", 0);	//高額療養費制度計算後の負担金額（公費あり）の月次合計
			$sum_futankin = $tmp_db->get("SUM_FUTANKIN", 0);				//高額療養費制度計算後の負担金額の月次合計

			//************************************************************************************
			//保険者レコード　保険者番号がない場合は出力しないこと
			//************************************************************************************
			if($hokenja_no != ''){
				$ho_arr = array();
				$ho_arr[] = HO_CODE;
				$ho_arr[] = str_pad($hokenja_no, 8, ' ', STR_PAD_LEFT);
				if($hoken_kigou != ''){
					$ho_arr[] = $hoken_kigou;
				}else{
					$ho_arr[] = '';
				}
				$ho_arr[] = $hoken_no;

				//その患者の、期間内のカルテテーブルの数を診療実日数とした
				$ho_arr[] = $jitu_nissu;	//診療実日数
				$ho_arr[] = $gokei;			//合計点数

				$ho_arr[] = '';
				$ho_arr[] = '';
				$ho_arr[] = $syokumu_jiyuu;
				$ho_arr[] = '';

				//***************************************************************************************************************
				//高額療養費 最終負担金
				//
				//  ≪公費なし≫
				//  ①患者負担金額が「自己負担月額上限」以上になり、患者負担金額カットが実施された場合、
				//  ②「70歳以上75歳未満」で「負担割合が1割」で「自己負担月額が7001円以上」の場合、
				//     （ただし、8桁の保険者番号で上2桁が「39」の場合と、誕生日が1944年4月2日以降の場合を除く ≪追加仕様 #2632≫）
				//  最終の患者負担額の値を、ＨＯレコード「(11)負担金額・医療保険」に記録してください　→　≪仕様変更 #3989≫
				//
				//  ≪公費あり≫
				//  ③点数合計×10×患者負担割合　の値が「自己負担金額上限」欄の数値よりも大きい時
                //    （患者負担金額カットが"高額療養費"で実施された 場合に限る #3320）　→　≪削除 #3916≫
				//     (ただし、自己負担金額上限が「0」の場合は除く #3916)
				//  ④「70歳以上75歳未満」で「負担割合が1割」で「点数合計×10×患者負担割合　が7001円以上」の場合、≪追加仕様 #2119≫　→　≪仕様変更 #2341≫
				//     （ただし、8桁の保険者番号で上2桁が「39」の場合と、誕生日が1944年4月2日以降の場合を除く ≪追加仕様 #2632≫）
				//  下記実装部のコメント参照のこと　→　≪仕様変更 #3989≫
				//
				//  また、この時紙レセプトにも記載をお願いします（位置は高額療養費での印字位置と同じ右下の「一部負担」欄です）
				//
				//***************************************************************************************************************
				$futan_wariai_org = (int)$p_db->get("FUTAN_WARIAI", $page);
                $futankin_without_kouhi =  $tmp_db->get("SUM_FUTANKIN_WITHOUT_KOHI", 0);

//                print('$hokenja_no=>'.$hokenja_no."\n");
// 				print('$birth=>'.$birth."\n");
// 				print('$age=>'.$age."\n");
// 				print('$futan_wariai_org=>'.$futan_wariai_org."\n");
// 				print('$sum_futankin=>'.$sum_futankin."\n");
// 				print('$sum_tmp_futankin=>'.$sum_tmp_futankin."\n");
// 				print('$futankin_without_kouhi=>'.$futankin_without_kouhi."\n");

				//特例月チェック
				$ho_add_flag = false;	//特例月パターンに当てはまり、負担金額・医療保険を設定したか否かのフラグ
				if(specialCaseMonthCheck($birth, $get_this_date)){
					//もし特例月条件に当てはまった、かつ保険者番号上2ケタが39から始まっていたら
					if(mb_substr($hokenja_no,0,2) == "39"){
						//患者負担金上限額は「患者詳細画面」高額療養費上限額欄の1/2にて計算
						$special_case_kogaku_getsuji_max = $kogaku_getsuji_max / 2;
						if(strlen($hokenja_no) == 8){		//8ケタだったら
							if($futankin_without_kouhi >= $special_case_kogaku_getsuji_max){
								$ho_add_flag = true;
								$ho_arr[] = $special_case_kogaku_getsuji_max;
							}
						}else{	//8ケタ以外だったら
							if($futan_wariai_org == 3 && $futankin_without_kouhi >= $special_case_kogaku_getsuji_max){
								$ho_add_flag = true;
								$ho_arr[] = $special_case_kogaku_getsuji_max;
							}else if($futan_wariai_org == 1 && ($futankin_without_kouhi * 2) >= $special_case_kogaku_getsuji_max){
								if($futankin_without_kouhi < $special_case_kogaku_getsuji_max){
									$ho_add_flag = true;
									$ho_arr[] = $futankin_without_kouhi;
								}else if($futankin_without_kouhi >= $special_case_kogaku_getsuji_max){
									$ho_add_flag = true;
									$ho_arr[] = $special_case_kogaku_getsuji_max;
								}
							}
						}
					}
				}

// 				print('$ho_add_flag=>'.$ho_add_flag."\n");
				if(!$ho_add_flag){
					if($kouhi_flg == 1){

                        if ($futankin_without_kouhi > 0
                            && $futankin_without_kouhi >= $kogaku_getsuji_max
                        ) {     //③
							if($kogaku_getsuji_max > 0){
								$ho_arr[] = $kogaku_getsuji_max;
							}else{
								$ho_arr[] = '';
							}
                        } else if ($age >= 70 && $age < 75 && $futan_wariai_org == 1 && $futankin_without_kouhi >= 7001) {    //④
                            if (!kohiExceptionCheck($hokenja_no, $birth)) {    //#2632 8桁の保険者番号で上2桁が「39」の場合と、誕生日が1944年4月2日以降の場合は上記の仕様に対して例外となる
                                //#2341 公費計算前患者負担額が14000円未満の場合、公費計算前患者負担額を記録。14000円以上の場合、14000円を記録
                                ($futankin_without_kouhi < 14000) ? $ho_arr[] = $futankin_without_kouhi : $ho_arr[] = 14000;
                            } else {
                                $ho_arr[] = '';        //負担金額・医療保険
                            }
                        } else {
                            $ho_arr[] = '';        //負担金額・医療保険
                        }
					}else{
						if($sum_futankin > 0 && $sum_futankin < $futankin_without_kouhi){		//①
							$ho_arr[] = $sum_futankin;
						}else if($age >= 70 && $age < 75 && $futan_wariai_org == 1 && $sum_futankin >= 7001){	//②
							if(!kohiExceptionCheck($hokenja_no, $birth)){	//#2632 8桁の保険者番号で上2桁が「39」の場合と、誕生日が1944年4月2日以降の場合は上記の仕様に対して例外となる
								$ho_arr[] = $sum_futankin;
							}else{
                                $ho_arr[] = '';		//負担金額・医療保険
                            }
						}else{
							$ho_arr[] = '';		//負担金額・医療保険
						}
					}
				}

				$ho_arr[] = '';		//減免区分（算定コメントによる値の設定があるが、ここでは判定できないので処置をループしたあとに改めて設定している）
				$ho_arr[] = '';		//減額割合
				$ho_arr[] = '';		//減額金額

				//$csv .= create_csv($ho_arr);
				$HOcsv = create_csv($ho_arr);
			}

			//************************************************************************************
			//公費レコード
			//************************************************************************************
			//公費がないなら出力しない
			if($kouhi_flg == 1 && $futan_type != 1){
				$codes = array();

				if($kohi_futan_no1 != '' && $kohi_futan_no2 != ''){

					//公費番号が複数ある場合には、手引書のP.19にある順番でKOレコードを複数作成する必要がございます。
					//P.19に記載のない公費に関しましては番号の若い順でお願いします。
					$check_code1 = mb_substr($kohi_futan_no1,0,2);
					$check_code2 = mb_substr($kohi_futan_no2,0,2);
					$cnt = 0;
					$hit1 = 0;
					$hit2 = 0;
					foreach($p_kohi_type_code as $code => $value){
						$cnt++;
						if($code == $check_code1){
							$hit1 = $cnt;
						}
						if($code == $check_code2){
							$hit2 = $cnt;
						}
					}


					//両方ともP.19に記載のない公費であれば番号の若い順に出力
					if($hit1 == 0 && $hit2 == 0){
						if($kohi_futan_no1 < $kohi_futan_no2){
							$codes[0]['futan_no'] = $kohi_futan_no1;
							$codes[0]['jyukyu_no'] = $kohi_jyukyu_no1;
							$codes[1]['futan_no'] = $kohi_futan_no2;
							$codes[1]['jyukyu_no'] = $kohi_jyukyu_no2;
						}else{
							$codes[0]['futan_no'] = $kohi_futan_no2;
							$codes[0]['jyukyu_no'] = $kohi_jyukyu_no2;
							$codes[1]['futan_no'] = $kohi_futan_no1;
							$codes[1]['jyukyu_no'] = $kohi_jyukyu_no1;
						}
					//両方とも記載ありの場合、記載されている順番に出力
					}else if($hit1 != 0 && $hit2 != 0){
						if($hit1 < $hit2){
							$codes[0]['futan_no'] = $kohi_futan_no1;
							$codes[0]['jyukyu_no'] = $kohi_jyukyu_no1;
							$codes[1]['futan_no'] = $kohi_futan_no2;
							$codes[1]['jyukyu_no'] = $kohi_jyukyu_no2;
						}else{
							$codes[0]['futan_no'] = $kohi_futan_no2;
							$codes[0]['jyukyu_no'] = $kohi_jyukyu_no2;
							$codes[1]['futan_no'] = $kohi_futan_no1;
							$codes[1]['jyukyu_no'] = $kohi_jyukyu_no1;
						}
					//公費1が記載あり公費で公費2が記載なし公費の場合
					}else if($hit1 != 0){
						$codes[0]['futan_no'] = $kohi_futan_no1;
						$codes[0]['jyukyu_no'] = $kohi_jyukyu_no1;
						$codes[1]['futan_no'] = $kohi_futan_no2;
						$codes[1]['jyukyu_no'] = $kohi_jyukyu_no2;
					//公費2が記載あり公費で公費1が記載なし公費の場合
					}else if($hit2 != 0){
						$codes[0]['futan_no'] = $kohi_futan_no2;
						$codes[0]['jyukyu_no'] = $kohi_jyukyu_no2;
						$codes[1]['futan_no'] = $kohi_futan_no1;
						$codes[1]['jyukyu_no'] = $kohi_jyukyu_no1;
					}

					$csv .= create_ko($codes, $jitu_nissu, $gokei, $kohi_futankin);
				}else if($kohi_futan_no2 != ''){
					$codes[0]['futan_no'] = $kohi_futan_no2;
					$codes[0]['jyukyu_no'] = $kohi_jyukyu_no2;
					$csv .= create_ko($codes, $jitu_nissu, $gokei, $kohi_futankin);
				}else{
					$codes[0]['futan_no'] = $kohi_futan_no1;
					$codes[0]['jyukyu_no'] = $kohi_jyukyu_no1;
					$csv .= create_ko($codes, $jitu_nissu, $gokei, $kohi_futankin);
				}

			}



	if($db->rows > 0){

		//カルテ総数
		$row_num = $db->rows;


		//************************************************************************************
		//傷病名部位レコード
		//************************************************************************************
        //#3104 コメントだけが採用されている部位病名のHSレコードの記録に関して
        //処置グループIDが 99,112,113,114,115,1000
        $ignore_group_arr = array(99,112,113,114,115,1000);
        $bui_arr = array();
		for($now_page=0; $now_page<$row_num; $now_page++){
			$karte_id = $db->get("ID", $now_page);								//カルテID
            $jyushin_date = $db->get("JYUSHIN_DATE", $now_page);				//受診日
            $jyushinDateYmd = date("Ymd", strtotime($jyushin_date));
            $dataKey = $dateMaster[$jyushinDateYmd];

            $sql  = "SELECT BUI_ID, SYOCHI_ID FROM T_KARTE_SYOCHI ";
            $sql .= "WHERE KARTE_ID = ". $karte_id;
            $bui = new EzDBMulti($DB_NAME);
            $bui->sql($sql);
            ///print_r($sql);

            for($n=0; $n<$bui->rows; $n++){
                $bui_id = $bui->get("BUI_ID", $n);
                $syochi_id = $bui->get("SYOCHI_ID", $n);
                if(in_array($bui_id, $bui_arr) == false){
                    $syochi_group_id = $syochiMaster[$syochi_id][$dataKey]['SYOCHI_GROUP_ID'];
                    ///print($syochi_id . "->" . $syochi_group_id . "\n");
                    if(in_array($syochi_group_id, $ignore_group_arr) == false){
                        $bui_arr[] = $bui_id;
                    }
                }
            }
        }
        ///print_r($bui_arr);

		//そのカルテに紐づく処置に紐づく部位情報
		$tmp_hs_array = array();
		for($n=0; $n<count($bui_arr); $n++){
			$bui_id = $bui_arr[$n];					//部位ID

			//そのカルテの部位情報を取得
			$sql  = "SELECT * FROM T_KARTE_BUI WHERE ID = ".$bui_id;
			$sql .= " AND DEL_FLG = 0";
			$bui_db->sql($sql);

			$karte_bui_id = $bui_db->get("ID", 0);					//部位ID
			$bui_ue = $bui_db->get("BUI_UE", 0);					//部位上
			$bui_shita = $bui_db->get("BUI_SHITA", 0);				//部位下

			$sisiki_code = '';
			//$sisiki_code = create_sisiki_code($bui_ue, $bui_shita);

			//傷病名コード取得
			$sql = "SELECT * FROM T_KARTE_SYOBYO WHERE KARTE_BUI_ID = ".$karte_bui_id;
			$sub = " AND TYPE = 1 ORDER BY SORT_NUM ";
			$s_db->sql($sql.$sub);
			$syobyo = $s_db->get("CODE", 0);
			$syobyo_name = $s_db->get("NAME", 0);


			//修飾語コード取得
			$sub = " AND TYPE = 2 ORDER BY SORT_NUM ";
			$s_db->sql($sql.$sub);
			$shusyoku = '';

			if($s_db->rows > 0){
				//修飾語コードを取得（最大20個まで）
				$syusyoku_num = $s_db->rows;
				if($syusyoku_num > 20) $syusyoku_num = 20;
				for($i=0; $i<$syusyoku_num; $i++){
					$shusyoku .= $s_db->get("CODE", $i);
				}
			}

			//病態移行取得
			$byotai_flg = 0;
			$hs_sub_csv = '';		//病態移行あり、または併存傷病名ありの場合の2レコード目以降のCSV
			$sub = " AND TYPE = 4 ORDER BY SORT_NUM ";
			$s_db->sql($sql.$sub);
			if($s_db->rows > 0){
				//病態移行が存在する場合、何番目の要素として登録されたかを取得しておく
				$byotai_flg = $s_db->get("SORT_NUM", 0);
			}


			//もし病態移行が存在すれば、その前後で別々に問い合わせる必要がある
			$heizon_cnt = 0;
			if($byotai_flg > 0){
				//病態移行前　併存傷病名数取得
				$sub = " AND TYPE = 3 AND SORT_NUM < ".$byotai_flg." ORDER BY SORT_NUM ";
				$s_db->sql($sql.$sub);
				$heizon_num = 0;		//併存傷病名数

				//併存傷病が存在した場合（存在しなければ1レコード目に病態移行前が記録されるだけ）
				if($s_db->rows > 0){
					//併存傷病名数を取得
					$heizon_num = $s_db->rows;
					$heizon_cnt = $heizon_num;

					for($i=0; $i<$heizon_num; $i++){
						$entry_num = $s_db->get("SORT_NUM", $i);

						//カンマの直後の傷病名を取得すること
						$heizon_sql  = "SELECT * FROM T_KARTE_SYOBYO WHERE KARTE_BUI_ID = ".$karte_bui_id;
						$heizon_sql .= " AND TYPE = 1 AND SORT_NUM > ".$entry_num." ORDER BY SORT_NUM";
						$dbo = new EzDBMulti($DB_NAME);
						$dbo->sql($heizon_sql);

						if($dbo->rows > 0){
							//病態移行前の併存傷病を記録
							$hs_sub_csv .= HS_CODE.',,,,'.$dbo->get("CODE", 0).',,,,1,,,,'.CSV_BREAK;
						}
					}
				}

				//病態移行後　併存傷病名数取得
				$sub = " AND TYPE = 3 AND SORT_NUM > ".$byotai_flg." ORDER BY SORT_NUM ";
				$s_db->sql($sql.$sub);
				$heizon_num = 0;		//併存傷病名数

				//併存傷病が存在した場合
				if($s_db->rows > 0){
					//併存傷病名数を取得
					$heizon_num = $s_db->rows;

					for($i=0; $i<$heizon_num; $i++){
						$entry_num = $s_db->get("SORT_NUM", $i);

						//病態移行後の一レコード目は併存傷病名数を記録
						if($i == 0){
							//病態移行後かつカンマより前の傷病名を取得
							$heizon_sql  = "SELECT * FROM T_KARTE_SYOBYO WHERE KARTE_BUI_ID = ".$karte_bui_id;
							$heizon_sql .= " AND TYPE = 1 AND SORT_NUM < ".$entry_num." AND SORT_NUM > ".$byotai_flg." ORDER BY SORT_NUM";
							$dbo = new EzDBMulti($DB_NAME);
							$dbo->sql($heizon_sql);

							if($dbo->rows > 0){
								//病態移行後の併存傷病を記録
								$tmp = $heizon_num + 1;
								$hs_sub_csv .= HS_CODE.',,,,'.$dbo->get("CODE", 0).',,,'.$tmp.',2,,,,'.CSV_BREAK;
							}
						}

						//病態移行後かつカンマより後ろの傷病名を取得
						$heizon_sql  = "SELECT * FROM T_KARTE_SYOBYO WHERE KARTE_BUI_ID = ".$karte_bui_id;
						$heizon_sql .= " AND TYPE = 1 AND SORT_NUM > ".$entry_num." ORDER BY SORT_NUM";
						$dbo = new EzDBMulti($DB_NAME);
						$dbo->sql($heizon_sql);

						if($dbo->rows > 0){
							//病態移行後の併存傷病を記録
							$hs_sub_csv .= HS_CODE.',,,,'.$dbo->get("CODE", 0).',,,,2,,,,'.CSV_BREAK;
						}
					}

				//併存傷病名はない場合、病態移行後の傷病名のみ記録
				}else{
					//病態移行直後の病名を2レコード目に記録とする
					$byoutai_sql  = "SELECT * FROM T_KARTE_SYOBYO WHERE KARTE_BUI_ID = ".$karte_bui_id;
					$byoutai_sql .= " AND TYPE = 1 AND SORT_NUM > ".$byotai_flg;

					$s_db->sql($byoutai_sql);
					if($s_db->rows > 0){
						$hs_sub_csv .= HS_CODE.',,,,'.$s_db->get("CODE", 0).',,,,2,,,,'.CSV_BREAK;
					}
				}

			//病態移行なしの場合
			}else{

				//併存傷病名数取得
				$sub = " AND TYPE = 3 ORDER BY SORT_NUM ";
				$s_db->sql($sql.$sub);
				$heizon_num = 0;		//併存傷病名数
				if($s_db->rows > 0){
					//併存傷病名数を取得
					$heizon_num = $s_db->rows;
					$heizon_cnt = $heizon_num;

					for($i=0; $i<$heizon_num; $i++){
						$entry_num = $s_db->get("SORT_NUM", $i);

						//カンマの直後の傷病名を取得すること
						$heizon_sql  = "SELECT * FROM T_KARTE_SYOBYO WHERE KARTE_BUI_ID = ".$karte_bui_id;
						$heizon_sql .= " AND TYPE = 1 AND SORT_NUM > ".$entry_num." ORDER BY SORT_NUM";

						$dbo = new EzDBMulti($DB_NAME);
						$dbo->sql($heizon_sql);

						if($dbo->rows > 0){
							$hs_sub_csv .= HS_CODE.',,,,'.$dbo->get("CODE", 0).',,,,,,,,'.CSV_BREAK;
						}
					}
				}
			}

			$hs_arr = array();
			$hs_arr[] = HS_CODE;
			$hs_arr[] = '';					//診療開始日（入院外はスキップ）
			$hs_arr[] = '';					//転帰区分（入院外はスキップ）

			//$hs_arr[] = $sisiki_code;		//歯式（傷病名）
			$hs_arr[] = '';					//歯式（傷病名）
			$hs_arr[] = $syobyo;			//傷病名コード
			$hs_arr[] = $shusyoku;			//修飾語コード
			($syobyo == MICODE_CODE) ? $hs_arr[] = $syobyo_name : $hs_arr[] = '';	//傷病名称

			//併存傷病名数がゼロ以上なら数を記録
			($heizon_cnt > 0) ? $hs_arr[] = $heizon_cnt+1 : $hs_arr[] = '';
			//病態移行が存在すれば病態移行コード1を記録
			($byotai_flg > 0) ? $hs_arr[] = 1 : $hs_arr[] = '';

			$hs_arr[] = '';					//主傷病
			$hs_arr[] = '';					//コメントコード
			$hs_arr[] = '';					//補足コメント
			$hs_arr[] = '';					//歯式（補足コメント）
			$hs_csv = create_csv($hs_arr);
			if($hs_sub_csv != '') $hs_csv .= $hs_sub_csv;

			//その患者のHSレコードを一時格納
			$tmp_hs_array[$karte_bui_id]['data'] = $hs_csv;
			$tmp_hs_array[$karte_bui_id]['syobyo'] = $syobyo;
			$tmp_hs_array[$karte_bui_id]['bui_ue'] = $bui_ue;
			$tmp_hs_array[$karte_bui_id]['bui_shita'] = $bui_shita;

			$tmp_hs_array[$karte_bui_id]['heizon_cnt'] = ($heizon_cnt > 0) ? $heizon_cnt+1 : 0;			//併存傷病名数がいくつのHSレコードであったか
			$tmp_hs_array[$karte_bui_id]['byotai_iko_number'] = $byotai_flg;	//何番目の病態移行HSレコードであったか
		}

		global $mt_shochi_list;
		$sisiki_code = '';
		$hs_csv_list = array();
		$reset_list = array();
		$mt_sisiki_list = array();
		foreach($tmp_hs_array as $id => $val){
			//すでに削除用登録されていれば処理を飛ばす
			if(array_search($id, $reset_list) === false){

				$syobyo = $val['syobyo'];
				$t_csv = $val['data'];

				if(array_search($syobyo, $mt_shochi_list) === false){
					$bui_ue = $val['bui_ue'];
					$bui_shita = $val['bui_shita'];
					foreach($tmp_hs_array as $tmp_id => $tmp_val){
						//自分以外で、全く同様の内容のCSVが存在したら歯式をマージさせる
						if($t_csv == $tmp_val['data'] && $id != $tmp_id){
							$bui_db->sql("SELECT * FROM T_KARTE_BUI WHERE ID = ".$tmp_id." AND DEL_FLG = 0");
							$bui_ue = merge_bui($bui_ue, $bui_db->get("BUI_UE", 0));					//部位上
							$bui_shita = merge_bui($bui_shita, $bui_db->get("BUI_SHITA", 0));			//部位下
							$reset_list[] = $tmp_id;
						}
					}
					$sisiki_code = create_sisiki_code($bui_ue, $bui_shita);

				}else{
					//傷病名がMT関連のため、HSレコードを出力
					$sisiki_code = create_sisiki_code($val['bui_ue'], $val['bui_shita']);

					//20110821 MT関連のレコードにおいて、部位が完全に含まれる場合だけはマージする処理を増設
					$mt_sisiki_list[$id]['sisiki'] = $sisiki_code;
					$mt_sisiki_list[$id]['syobyo'] = $syobyo;
					//ただし、マージ時には、併存傷病の有無、病態移行の有無もチェックし、それぞれ別の病名と考えること 2011/10/5
					$mt_sisiki_list[$id]['heizon_cnt'] = $val['heizon_cnt'];
					$mt_sisiki_list[$id]['byotai_iko_number'] = $val['byotai_iko_number'];
				}

				//歯式を挿入してＣＳＶ完成させて
				$tmp_arr = explode(',', $t_csv);
				$tmp_arr[3] = $sisiki_code;
				$hs_csv = implode(',', $tmp_arr);
				$hs_csv_list[$id] = $hs_csv;
			}
		}

		$reset_list = merge_mt($mt_sisiki_list, $reset_list);


		//余計なHSレコードを削除
		foreach($reset_list as $del_id){
			unset($hs_csv_list[$del_id]);
		}

		foreach($hs_csv_list as $hs_csv){
			//その患者のHSレコードを格納
			$csv_hs_array[$patient_id][$henrei_flg][$saiseikyu][] = $hs_csv;
		}

		//************************************************************************************
		//SSレコード系統
		//************************************************************************************

		//カルテの回数分出力する
		for($now_page=0; $now_page<$row_num; $now_page++){

			$karte_id = $db->get("ID", $now_page);								//カルテID
			if(array_search($karte_id, $illegal_karte) !== FALSE) continue;
			$jyushin_date = $db->get("JYUSHIN_DATE", $now_page);				//受診日
			$jyushinDateYmd = date("Ymd", strtotime($jyushin_date));
			$dataKey = $dateMaster[$jyushinDateYmd];

			//************************************************************************************
			// 処置からレコードを作成
			//************************************************************************************

			//処置を取得
			$sql  = "SELECT * FROM T_KARTE_SYOCHI WHERE KARTE_ID = ".$karte_id;

			$s_db->sql($sql);
			$syochi_num = $s_db->rows;

			//まずすべての処置をチェック。処置の加算コード化準備を実施。合わせて処置ごとにグループ分け
			//さらに処方せんの算定があるかも合わせてチェック。処方せん処置（処置IDが729,762）があればフラグ立て
			$kasan_mst_array = null;
			$ss_data = array();
			$si_data = array();
			$tmp_iy_data = array();
			$iy_data = array();
			$to_data = array();
			$co_data = array();
			$sisiki_list = array();
			$mapping_checked_list = array();
			$syoho_flg = false;


			for($k=0; $k<$syochi_num; $k++){

				$syochi_id = $s_db->get("SYOCHI_ID", $k);	//処置ID
				$bui_id = $s_db->get("BUI_ID", $k);			//部位ID

				//処方せん処置（処置IDが729,762）があればフラグ立て
				if($syochi_id == '729' || $syochi_id == '762') $syoho_flg = true;

				//「災１」のコメント（処置ID=2658）があればフラグ立て
				if($syochi_id == '2658') $sai1_flg = true;

				//その処置の部位情報を取得
				$sql  = "SELECT * FROM T_KARTE_BUI WHERE ID = ".$bui_id;
				$sql .= " AND DEL_FLG = 0";
				//$sql .= " AND KARTE_ID = ".$karte_id;
				$bui_db->sql($sql);

				$bui_ue = $bui_db->get("BUI_UE", 0);					//部位上
				$bui_shita = $bui_db->get("BUI_SHITA", 0);				//部位下

				$sisiki_list[$k] = create_sisiki_code($bui_ue, $bui_shita);


				//点数は処置マスタテーブルからではなく、カルテ処置テーブルから取得とする
				$tensu = $s_db->get("TENSU", $k);

				//処置IDを元に処置の詳細情報を取得
/*				$dbo = new EzDBMulti($DB_NAME);
				$sql  = "SELECT * FROM ".MASTER_DB.".M_SYOCHI_MASTER WHERE ID = ".$syochi_id;
				$sql .= " AND DATA_KIGEN_S <= '".$jyushin_date."' AND DATA_KIGEN_E >= '".$jyushin_date."' ";
				$sql .= " LIMIT 0, 1 ";
				$dbo->sql($sql);
*/
				//診療行為コード1に＠つきでコードが入っていたら、この処置は加算コード用と判断すること
				$ss_code1 = $syochiMaster[$syochi_id][$dataKey]['SS_CODE1'];
				$si_code = $syochiMaster[$syochi_id][$dataKey]['SI_CODE'];
				$to_code = $syochiMaster[$syochi_id][$dataKey]['TO_CODE'];
				$iy_code1 = $syochiMaster[$syochi_id][$dataKey]['IY_CODE1'];
				$co_code = $syochiMaster[$syochi_id][$dataKey]['CO_CODE'];

				//一文字目が＠であるか否かを判断材料とする。後ろにカンマ区切りで続く予定
				if(mb_substr($ss_code1,0,1) == '@'){

					$ss_code1_arr = explode(',', $ss_code1);
					$ss_kasan_code1   = $syochiMaster[$syochi_id][$dataKey]['SS_KASAN_CODE1'];			//加算コード1(カンマ区切り想定)
					$ss_kasan_kizami1 = $syochiMaster[$syochi_id][$dataKey]['SS_KASAN_KIZAMI1'];			//加算きざみ1(カンマ区切り想定)
					$ss_kasan_suryo1  = $syochiMaster[$syochi_id][$dataKey]['SS_KASAN_SURYO1'];			//加算数量1(カンマ区切り想定)
					$ss_kasan_sort  = $syochiMaster[$syochi_id][$dataKey]['SS_KASAN_SORT'];				//加算記録順
					$ss_kasan_code1_arr = explode(',', $ss_kasan_code1);
					$ss_kasan_kizami1_arr = explode(',', $ss_kasan_kizami1);
					$ss_kasan_suryo1_arr = explode(',', $ss_kasan_suryo1);

					$honsu = $s_db->get("HONSU", $k);	//算定数
					$check_digit = md5(mt_rand());		//チェックデジット

					foreach($ss_code1_arr as $scode){
						$ss_code = mb_substr($scode, 1);

						$tmp_kasan_array = array();
						$tmp_kasan_num_array = array();
						$tmp_kasan_org_num_array = array();
						$tmp_kasan_kizami_array = array();

						foreach($ss_kasan_code1_arr as $key => $val){
							$tmp_kasan_array[$key] = $val;
							if($ss_kasan_kizami1_arr[$key] == 1){
								//もし加算数量が設定されていなければ算定数を代入
								if($ss_kasan_suryo1_arr[$key] == ''){
									$tmp_kasan_num_array[$key] = $honsu;
								}else{
									$tmp_kasan_num_array[$key] = $ss_kasan_suryo1_arr[$key];
								}
								$tmp_kasan_kizami_array[$key] = 1;
							}else{
								$tmp_kasan_num_array[$key] = $honsu;
								$tmp_kasan_kizami_array[$key] = 0;
							}

							if($ss_kasan_suryo1_arr[$key] == ''){
								$tmp_kasan_org_num_array[$key] = $honsu;
							}else{
								$tmp_kasan_org_num_array[$key] = $ss_kasan_suryo1_arr[$key];
							}

						}

                        $ss = new SS();
                        $ss->code = $tmp_kasan_array;
                        $ss->suryo = $tmp_kasan_num_array;
                        $ss->org_suryo = $tmp_kasan_org_num_array;
                        $ss->kizami = $tmp_kasan_kizami_array;
                        $ss->tensu = $tensu;
                        $ss->bui_code = $sisiki_list[$k];
                        $ss->check_digit = $check_digit;
                        $ss->sort = $ss_kasan_sort;

						$kasan_mst_array[$ss_code][] = $ss;
					}

				//＠つきでないものは各データ配列に格納しておく

				//歯科診療行為レコード
				}else if($ss_code1 != ''){
					//同時に合算処理を含む可能性ありなデータ
					$ss_data[] = $k;

				//医科診療行為レコード
				}else if($si_code != ''){
					$si_data[] = $k;

				//医薬品レコード
				}else if($iy_code1 != ''){
					//合剤グループ分けを実行して再編が必要なデータ
					$tmp_iy_data[] = $k;

				//特定器材レコード
				}else if($to_code != ''){
					$to_data[] = $k;

				//コメントレコード
				}else if($co_code != ''){
					$co_data[] = $k;

				}
			}


			//医薬品処置のみをチェックして、合剤グループがないかチェック
			foreach($tmp_iy_data as $r){
				$youhou = $s_db->get("YOUHOU", $r);
				$honsu = $s_db->get("HONSU", $r);
				$syochi_id = $s_db->get("SYOCHI_ID", $r);			//処置ID
				$yakuzai_flg = $s_db->get("YAKUZAI_FLG", $r);		//医薬品区分

				//医薬品処置を用法・本数でグループ分けして、連想配列に格納。
				/*
				 * 用法および算定数（日数）(HONSU)が同じ２種類以上の薬剤に関しては「合剤」とする
				 * 2011.04.05 用法が空欄であっても合剤とすること
				 * 薬剤区分の「４」だけは他の薬剤とは合剤になりません。
				 * １と２、１と３、２と３という組み合わせはありうるそうですが、
				 * ４は他の薬剤と合剤にはならないそうです。
				 *
				 * #2856 コメント12
				 * 用法で「痛時」を選択した場合は、登録されている医薬品区分値は無視して、医薬品区分は無条件に「2」と記録する(「2」なので、複数でも合剤計算はされません)。
				 */

				$yakuzai_group = 0;
				//用法が「痛時」なら医薬品区分を2に上書き
				if($youhou == 5){
					$yakuzai_flg = 2;
				}
				if($yakuzai_flg == '1'){
					$yakuzai_group = 1;
				}else if($yakuzai_flg == '4'){
					$yakuzai_group = 2;
				}else{
					$yakuzai_group = 0;
				}
				$iy_data[$yakuzai_group][$youhou][$honsu][$r] = $syochi_id;
			}

debug_print('==================================================================='."\n");
debug_dump($iy_data,'$iy_data');
debug_print('==================================================================='."\n");
debug_dump($ss_data, '$ss_data');

			//歯科診療行為処置のみを再問い合わせ
			foreach($ss_data as $k){

				$honsu_diff_result = HONSU_EQUAL;	//ベースとなる診療行為と加算処置の算定数の差による処理分岐用（#2643）
				$is_baisu = false;	//加算処置の数が、ベースとなる診療行為の倍数であるか
				$kasan_num = 0;		//加算処置の数

				$tmp_csv = '';
				$tmp_csv_2 = '';
				$syochi_id = $s_db->get("SYOCHI_ID", $k);	//処置ID
				$honsu = $s_db->get("HONSU", $k);

debug_print('-------------------------------------------------------------------'."\n");
debug_print('$syochi_id=>'.$syochi_id);


				//処置IDを元に処置の詳細情報を取得
/*				$dbo = new EzDBMulti($DB_NAME);
				$sql  = "SELECT * FROM ".MASTER_DB.".M_SYOCHI_MASTER WHERE ID = ".$syochi_id;
				$sql .= " AND DATA_KIGEN_S <= '".$jyushin_date."' AND DATA_KIGEN_E >= '".$jyushin_date."' ";
				$sql .= " LIMIT 0, 1 ";
				$dbo->sql($sql);

				$ss_code1 = $dbo->get("SS_CODE1", 0);
				$ss_code2 = $dbo->get("SS_CODE2", 0);
				$ss_code3 = $dbo->get("SS_CODE3", 0);
				$ss_code4 = $dbo->get("SS_CODE4", 0);
				$si_code  = $dbo->get("SI_CODE", 0);
				$to_code  = $dbo->get("TO_CODE", 0);
				$iy_code1 = $dbo->get("IY_CODE1", 0);
				$co_code  = $dbo->get("CO_CODE", 0);

				$iy_shiyoryo_kisai1 = $dbo->get("IY_SHIYORYO_KISAI1", 0);	//使用量の記載1
				$iy_shiyoryo1 = $dbo->get("IY_SHIYORYO1", 0);				//使用量1

				$mst_tensu    = $dbo->get("TENSU", 0);
				$mst_tensu70  = $dbo->get("TENSU70", 0);
				$mst_tensu150 = $dbo->get("TENSU150", 0);
				$ss_add_exception_flag = $dbo->get("SS_ADD_EXCEPTION_FLAG", 0);

				$sikibetu_code = $dbo->get("SIKIBETU_CODE", 0);					//診療識別コード
*/
				$ss_code1 = $syochiMaster[$syochi_id][$dataKey]['SS_CODE1'];
				$ss_code2 = $syochiMaster[$syochi_id][$dataKey]['SS_CODE2'];
				$ss_code3 = $syochiMaster[$syochi_id][$dataKey]['SS_CODE3'];
				$ss_code4 = $syochiMaster[$syochi_id][$dataKey]['SS_CODE4'];
				$si_code = $syochiMaster[$syochi_id][$dataKey]['SI_CODE'];
				$to_code = $syochiMaster[$syochi_id][$dataKey]['TO_CODE'];
				$iy_code1 = $syochiMaster[$syochi_id][$dataKey]['IY_CODE1'];
				$co_code = $syochiMaster[$syochi_id][$dataKey]['CO_CODE'];
				$iy_shiyoryo_kisai1 = $syochiMaster[$syochi_id][$dataKey]['IY_SHIYORYO_KISAI1'];
				$iy_shiyoryo1 = $syochiMaster[$syochi_id][$dataKey]['IY_SHIYORYO1'];
				$mst_tensu = $syochiMaster[$syochi_id][$dataKey]['TENSU'];
				$mst_tensu70 = $syochiMaster[$syochi_id][$dataKey]['TENSU70'];
				$mst_tensu70_150 = $syochiMaster[$syochi_id][$dataKey]['TENSU70_150'];
				$mst_tensu150 = $syochiMaster[$syochi_id][$dataKey]['TENSU150'];
				$mst_kasan_nyuyoji = $syochiMaster[$syochi_id][$dataKey]['KASAN_NYUYOJI'];
				$mst_kasan_konnan = $syochiMaster[$syochi_id][$dataKey]['KASAN_KONNAN'];
				$ss_add_exception_flag = $syochiMaster[$syochi_id][$dataKey]['SS_ADD_EXCEPTION_FLAG'];
				$sikibetu_code = $syochiMaster[$syochi_id][$dataKey]['SIKIBETU_CODE'];
				$bunrui = $syochiMaster[$syochi_id][$dataKey]['BUNRUI'];

				//乳幼児加算、困難（障）加算 #3930
				$kasan_nyuyoji_arr = array();
				$kasan_konnan_arr = array();
				$extra_code_ss1to4 = "";	//SS2～4にも引き継がれるコードを保持しておく

				//点数は処置マスタテーブルからではなく、カルテ処置テーブルから取得とする
				$tensu = $s_db->get("TENSU", $k);

				//合算処理を考慮することになる

				//合算処理レコード数を決定
				$record_num = 0;
				$last_flg = 0;
				if($ss_code2 != '') $record_num++;
				if($ss_code3 != '') $record_num++;
				if($ss_code4 != '') $record_num++;
				if($si_code != '') $record_num++;
				if($to_code != '') $record_num++;
				if($iy_code1 != '') $record_num++;

				//上から順にCSVを追加。最終レコードにはflgを立てて渡す。
				if($record_num == 0) $last_flg = 1;

				//$ss_kizami1		  = $dbo->get("SS_KIZAMI1", 0);					//診療行為きざみ1
				//$ss_kizami_suryo1 = $dbo->get("SS_KIZAMI_SURYO1", 0);			//診療行為きざみ数量1
				$ss_kizami1 = $syochiMaster[$syochi_id][$dataKey]['SS_KIZAMI1'];
				$ss_kizami_suryo1 = $syochiMaster[$syochi_id][$dataKey]['SS_KIZAMI_SURYO1'];

				//もし診療行為きざみ数量1が空欄の場合、処置登録画面で入力された算定数を記録する?
				//if($ss_kizami_suryo1 == '') $ss_kizami_suryo1 = $honsu;

				//加算コード用の空配列を作成
				$kasan_array = array(); //子要素はKasanDataを想定
				$kasan_num_array = array();

                $kasan_array_2 = array();		//2行目用
                $kasan_num_array_2 = array();	//2行目用

                for($l=0; $l<35; $l++){
					$kasan_array[$l] = null;
					$kasan_num_array[$l] = null;
				}

				$ss_kasan_code1 = $syochiMaster[$syochi_id][$dataKey]['SS_KASAN_CODE1'];			//加算コード1(カンマ区切り想定)
				$ss_kasan_kizami1 = $syochiMaster[$syochi_id][$dataKey]['SS_KASAN_KIZAMI1'];			//加算きざみ1(カンマ区切り想定)
				$ss_kasan_suryo1 = $syochiMaster[$syochi_id][$dataKey]['SS_KASAN_SURYO1'];			//加算数量1(カンマ区切り想定)
                $ss_kasan_sort  = $syochiMaster[$syochi_id][$dataKey]['SS_KASAN_SORT'];				//加算記録順

				$ss_kasan_code1_arr   = explode(',', $ss_kasan_code1);
				$ss_kasan_kizami1_arr = explode(',', $ss_kasan_kizami1);
				$ss_kasan_suryo1_arr  = explode(',', $ss_kasan_suryo1);

				//加算コードを弾込め
				for($l=0; $l<35; $l++){
					if($ss_kasan_code1_arr[$l] != ''){
                        $kasan_array[$l] = (object) array('code' => $ss_kasan_code1_arr[$l], 'sort' => $ss_kasan_sort);  //KasanData扱いにしたいのですが・・
                    } else {
                        $kasan_array[$l] = null;
                    }

					if($ss_kasan_kizami1_arr[$l] == 1){
						//もし加算数量が設定されていなければ算定数を代入
						if($ss_kasan_suryo1_arr[$l] == ''){
                            $kasan_num_array[$l] = $honsu;
                        }else{
                            $kasan_num_array[$l] = $ss_kasan_suryo1_arr[$l];
                        }
					}else{
						$kasan_num_array[$l] = '';
					}
				}

				//課題 #2726「注加算の記録順序が誤っています。」で受付エラー
				//「SS1に複数の加算コードが登録されている場合は加算コードを並び替えない」という仕様のほうで対応
				$kasan_sort_flg = 1;
				//SS_KASAN_CODE1に複数加算が２つ以上あれば、フラグを消しておき、並べ替え処理を行なわない
				$kasan_cnt = 0;
				foreach ($kasan_array as $key=>$val){
					if($val != null){
						$kasan_cnt++;
						if($kasan_cnt > 1){
							$kasan_sort_flg = 0;
							break;
						}
					}
				}
debug_print('$kasan_sort_flg=>'.$kasan_sort_flg);


				//もしその診療行為コードに対して加算用診療行為が用意されていたら、加算しておく。
debug_dump($kasan_mst_array, '$kasan_mst_array');
debug_dump($kasan_array, '$kasan_array');

				if(is_array($kasan_mst_array[$ss_code1])){

					///----------------------------------------------------------------
					$honsu_diff_result = HONSU_EQUAL;
					if($honsu == 1){
						$honsu_diff_result = HONSU_1;
					}else{
						foreach($kasan_mst_array[$ss_code1] as $number => $ss_obj){	//DM017,DM013・・・とかをループしてる

							//まずは回数だけ見て、パターン判定
							//$mapping_data = $ss_obj;

							//複数部位で同一診療行為に同一加算してる場合があるので、部位の一致を確認
							if($sisiki_list[$k] == $ss_obj->bui_code){

								//ベースとなる診療行為に紐付けられた加算処置の数
								$kasan_num++;

								$map_kasan_code = $ss_obj->code;	//加算コード
								$map_kasan_num = $ss_obj->suryo;	//加算数量
								$map_kasan_org_num = $ss_obj->org_suryo;	//加算数量(きざみ考慮なしの、本来の値)
								$map_kasan_kizami = $ss_obj->kizami;	//加算きざみ

								debug_dump($map_kasan_code, '$map_kasan_code');
								debug_dump($map_kasan_num, '$map_kasan_num');
								debug_dump($map_kasan_kizami, '$map_kasan_kizami');

								foreach($map_kasan_code as $key => $val){
									$kasan_honsu = $map_kasan_num[$key];
									$kasan_org_honsu = $map_kasan_org_num[$key];

									debug_print('$honsu=>'.$honsu."\n");
									debug_print('$kasan_honsu=>'.$kasan_honsu."\n");

									if($honsu == $kasan_honsu){
										;
									}else if($honsu > $kasan_honsu){
										$honsu_diff_result = KASAN_WITH_SMALL;

									}else if($honsu < $kasan_honsu){
										if($honsu_diff_result != KASAN_WITH_SMALL){
											$honsu_diff_result = KASAN_ALL_LARGE;
										}
									}

									if($kasan_honsu % $honsu == 0){
										$is_baisu = true;
									}
								}

							}
						}
					}
debug_print('$honsu_diff_result=>'.$honsu_diff_result);
debug_print('$kasan_num=>'.$kasan_num);
debug_print('$is_baisu=>'.$is_baisu);

					///----------------------------------------------------------------


					if($honsu_diff_result == KASAN_ALL_LARGE || $honsu_diff_result == KASAN_WITH_SMALL){
						//配列を複製！
						$kasan_array_2 = $kasan_array;
						$kasan_num_array_2 = $kasan_num_array;
					}

					/*
					 * 診療行為＝加算
					 * 診療行為≠加算 かつ 診療行為＝１
					 */
					if($honsu_diff_result == HONSU_EQUAL || $honsu_diff_result == HONSU_1){
						debug_print('@@pattern normal');

						///foreach($mapping_array[$ss_code1] as $number => $val){	//DM017,DM013・・・とかをループしてる
						for($number=0;$number<count($kasan_mst_array[$ss_code1]); $number++){

                            $ss_obj = $kasan_mst_array[$ss_code1][$number];
debug_dump($ss_obj, '**$ss_obj');
							// 20101230 条件追加　同一部位であれば加算すること
							if($sisiki_list[$k] == $ss_obj->bui_code && !in_array($ss_obj->check_digit, $mapping_checked_list)){

                                $map_kasan_code = $ss_obj->code;	//加算コード
                                $map_kasan_num = $ss_obj->suryo;	//加算数量
                                $map_kasan_kizami = $ss_obj->kizami;	//加算きざみ
                                $map_kasan_sort = $ss_obj->sort;    //加算表示順

								//加算コードの末尾に追加するために、最後の「空でない」要素の位置を取得
								$cnt = -1;
								foreach($kasan_array as $key => $val){
									if($val != null) continue;
									$cnt = $key;
									break;
								}

								if($cnt != -1){
									foreach($map_kasan_code as $key => $val){	// (ダミーだけど）DM013,EY999レベルのループ

										//加算コードを$add_arrayにセット↓
										$kasan_array[$cnt] = (object) array('code' => $val, 'sort' => $map_kasan_sort);  //KasanData扱いにしたいのですが・・

                                        //加算数量を$add_num_arrayにセット↓

										/** 加算分の点数は処置の点数に統合してしまうと、処置が複数個の場合に矛盾が出てくる…コメントアウト */
										//　加算分の点数の更新！！！
										// マッピング先が1対1か1対ｎ（加算側）か、場合によっては1対0…
										// ゼロの場合は無理やり処理するか…ゼロでも点数あわせのために、1（存在している）ことにする
										// さらに、本数が複数本の場合に加算数が本数以下の場合は加算は一回のみとして数調整
										//$kasan_honsu = $map_add_num[$key];
										//if($kasan_honsu == 0) $kasan_honsu++;
										//if($kasan_honsu < $honsu) $kasan_honsu = $honsu;
										//$tensu += $mapping_data['tensu'] * (($kasan_honsu + 1) - $honsu);

										// 20101230 加算コードマッピング時の点数更新は下記公式にて実行のこと
										// 加算数量＝加算処置の算定数÷対象処置の算定数
										// 点数＝対象処置点数＋加算処置点数×加算数量

										$kasan_suryo = intval($map_kasan_num[$key] / $honsu);

										if($map_kasan_kizami[$key] == 0){
											$kasan_suryo = 1;
											$kasan_num_array[$cnt] = '';
										}else{
											$kasan_num_array[$cnt] = $kasan_suryo;
										}

										if(!in_array($ss_obj->check_digit, $mapping_checked_list)){
											$tensu += $ss_obj->tensu * $kasan_suryo;
										}

										//使用済みマッピングを登録
										$mapping_checked_list[] = $ss_obj->check_digit;

										if($cnt > 34) break;
										else $cnt++;
									}
								}
							}
						}

					/*
					 * パターンB
					 * 診療行為＜加算
					 */
					}else if($honsu_diff_result == KASAN_ALL_LARGE){
						debug_print('@@pattern B');

						if($is_baisu && $kasan_num == 1){
							debug_print('@@pattern B - baisu');
							///foreach($mapping_array[$ss_code1] as $number => $val){	//DM017,DM013・・・とかをループしてる
							for($number=0;$number<count($kasan_mst_array[$ss_code1]); $number++){

								$ss_obj = $kasan_mst_array[$ss_code1][$number];

								// 20101230 条件追加　同一部位であれば加算すること
								if($sisiki_list[$k] == $ss_obj->bui_code && !in_array($ss_obj->check_digit, $mapping_checked_list)){

									$map_kasan_code = $ss_obj->code;	//加算コード
									$map_kasan_num = $ss_obj->suryo;	//加算数量
									$map_kasan_kizami = $ss_obj->kizami;	//加算きざみ
                                    $map_kasan_sort = $ss_obj->sort;    //加算表示順

									//加算コードの末尾に追加するために、最後の「空でない」要素の位置を取得
									$cnt = -1;
									foreach($kasan_array as $key => $val){
										if($val != null) continue;
										$cnt = $key;
										break;
									}

									if($cnt != -1){
										foreach($map_kasan_code as $key => $val){	// (ダミーだけど）DM013,EY999レベルのループ

											//加算コードを$add_arrayにセット↓
                                            $kasan_array[$cnt] = (object) array('code' => $val, 'sort' => $map_kasan_sort);  //KasanData扱いにしたいのですが・・

											//加算数量を$add_num_arrayにセット↓
											$kasan_suryo = intval($map_kasan_num[$key] / $honsu);

											if($map_kasan_kizami[$key] == 0){
												$kasan_suryo = 1;
												$kasan_num_array[$cnt] = '';
											}else{
												$kasan_num_array[$cnt] = $kasan_suryo;
											}

											if(!in_array($ss_obj->check_digit, $mapping_checked_list)){
												$tensu += $ss_obj->tensu * $kasan_suryo;
											}

											//使用済みマッピングを登録
											$mapping_checked_list[] = $ss_obj->check_digit;

											if($cnt > 34) break;
											else $cnt++;
										}
									}
								}
							}

						}else{

							//2行目用に先に複製しておく
							$tensu_2 = $tensu;
							$mapping_checked_list_tmp = array();

							//1行目＝add_array （1 を算定数としてセット）
							///foreach($mapping_array[$ss_code1] as $number => $val){	//DM017,DM013・・・とかをループしてる
							for($number=0;$number<count($kasan_mst_array[$ss_code1]); $number++){

								$ss_obj = $kasan_mst_array[$ss_code1][$number];

								// 20101230 条件追加　同一部位であれば加算すること
								if($sisiki_list[$k] == $ss_obj->bui_code && !in_array($ss_obj->check_digit, $mapping_checked_list)){

									$map_kasan_code = $ss_obj->code;	//加算コード
									$map_kasan_num = $ss_obj->suryo;	//加算数量
									$map_kasan_kizami = $ss_obj->kizami;	//加算きざみ
                                    $map_kasan_sort = $ss_obj->sort;    //加算表示順

									//加算コードの末尾に追加するために、最後の「空でない」要素の位置を取得
									$cnt = -1;
									foreach($kasan_array as $key => $val){
										if($val != '') continue;
										$cnt = $key;
										break;
									}

									if($cnt != -1){
										foreach($map_kasan_code as $key => $val){	// (ダミーだけど）DM013,EY999レベルのループ

											//加算コードを$add_arrayにセット↓
                                            $kasan_array[$cnt] = (object) array('code' => $val, 'sort' => $map_kasan_sort);  //KasanData扱いにしたいのですが・・

											//加算数量を$add_num_arrayにセット↓
											///$kasan_suryo = $map_add_num[$key];
											$kasan_suryo = 1;

											if($map_kasan_kizami[$key] == 0){
												$kasan_suryo = 1;
												$kasan_num_array[$cnt] = '';
											}else{
												$kasan_num_array[$cnt] = $kasan_suryo;
											}

											if(!in_array($ss_obj->check_digit, $mapping_checked_list_tmp)){
												$tensu += $ss_obj->tensu * 1;
											}

											//使用済みマッピングを登録（1行目では点数加算の重複を避けるためだけに消し込み＝tmpの方へセット）
											///$mapping_checked_list[] = $mapping_data['check_digit'];
											$mapping_checked_list_tmp[] = $ss_obj->check_digit;

											if($cnt > 34) break;
											else $cnt++;
										}
									}
								}
							}

							//2行目＝add_array_2 （登録された算定数から、ベースとなる処置の算定数-1 を引いた値を算定数としてセット）
							///foreach($mapping_array[$ss_code1] as $number => $val){	//DM017,DM013・・・とかをループしてる
							for($number=0;$number<count($kasan_mst_array[$ss_code1]); $number++){

								$ss_obj = $kasan_mst_array[$ss_code1][$number];

								// 20101230 条件追加　同一部位であれば加算すること
								if($sisiki_list[$k] == $ss_obj->bui_code && !in_array($ss_obj->check_digit, $mapping_checked_list)){

									$map_kasan_code = $ss_obj->code;	//加算コード
									$map_kasan_num = $ss_obj->suryo;	//加算数量
									$map_kasan_kizami = $ss_obj->kizami;	//加算きざみ
                                    $map_kasan_sort = $ss_obj->sort;    //加算表示順

									//加算コードの末尾に追加するために、最後の「空でない」要素の位置を取得
									$cnt = -1;
									foreach($kasan_array_2 as $key => $val){
										if($val != '') continue;
										$cnt = $key;
										break;
									}

									if($cnt != -1){
										foreach($map_kasan_code as $key => $val){	// (ダミーだけど）DM013,EY999レベルのループ

											//加算コードを$add_arrayにセット↓
                                            $kasan_array_2[$cnt] = (object) array('code' => $val, 'sort' => $map_kasan_sort);  //KasanData扱いにしたいのですが・・

											//加算数量を$add_num_arrayにセット↓
											///$kasan_suryo = $map_add_num[$key];
											$kasan_suryo = $map_kasan_num[$key] - ($honsu - 1);

											if($map_kasan_kizami[$key] == 0){
												$kasan_suryo = 1;
												$kasan_num_array_2[$cnt] = '';
											}else{
												$kasan_num_array_2[$cnt] = $kasan_suryo;
											}

											if(!in_array($ss_obj->check_digit, $mapping_checked_list)){
												$tensu_2 += $ss_obj->tensu * $kasan_suryo;
											}

											//使用済みマッピングを登録
											$mapping_checked_list[] = $ss_obj->check_digit;

											if($cnt > 34) break;
											else $cnt++;
										}
									}
								}
							}
						}

					/*
					 * パターンA
					 * 診療行為＞加算 かつ 複数加算なし
					 */
					}else if($honsu_diff_result == KASAN_WITH_SMALL && $kasan_num == 1){
						debug_print('@@pattern A');

						//2行目用に先に複製しておく
						$tensu_2 = $tensu;
						$mapping_checked_list_tmp = array();

						//1行目＝add_array （加算処置の算定数 を算定数としてセット）
						///foreach($mapping_array[$ss_code1] as $number => $val){	//DM017,DM013・・・とかをループしてる
						for($number=0;$number<count($kasan_mst_array[$ss_code1]); $number++){

							$ss_obj = $kasan_mst_array[$ss_code1][$number];

							// 20101230 条件追加　同一部位であれば加算すること
							if($sisiki_list[$k] == $ss_obj->bui_code && !in_array($ss_obj->check_digit, $mapping_checked_list)){

								$map_kasan_code = $ss_obj->code;	//加算コード
								$map_kasan_num = $ss_obj->suryo;	//加算数量
								$map_kasan_kizami = $ss_obj->kizami;	//加算きざみ
                                $map_kasan_sort = $ss_obj->sort;    //加算表示順

								//加算コードの末尾に追加するために、最後の「空でない」要素の位置を取得
								$cnt = -1;
								foreach($kasan_array as $key => $val){
									if($val != '') continue;
									$cnt = $key;
									break;
								}

								if($cnt != -1){
									foreach($map_kasan_code as $key => $val){	// (ダミーだけど）DM013,EY999レベルのループ

										//加算コードを$add_arrayにセット↓
                                        $kasan_array[$cnt] = (object) array('code' => $val, 'sort' => $map_kasan_sort);  //KasanData扱いにしたいのですが・・

										//加算数量を$add_num_arrayにセット↓
										$kasan_suryo = $map_kasan_num[$key];

										if($map_kasan_kizami[$key] == 0){
											$kasan_suryo = 1;
											$kasan_num_array[$cnt] = '';
										}else{
											$kasan_num_array[$cnt] = $kasan_suryo;
										}

										if(!in_array($ss_obj->check_digit, $mapping_checked_list)){
											$tensu += $ss_obj->tensu;
										}

										//使用済みマッピングを登録（1行目では点数加算の重複を避けるためだけに消し込み＝tmpの方へセット）
										$mapping_checked_list_tmp[] = $ss_obj->check_digit;

										if($cnt > 34) break;
										else $cnt++;
									}
								}
							}
						}

					/*
					 * パターンC
					 * 診療行為＞加算 かつ 複数加算あり
					 */
					}else if($honsu_diff_result == KASAN_WITH_SMALL && $kasan_num > 1){
						debug_print('@@pattern C');

						//2行目用に先に複製しておく
						$tensu_2 = $tensu;

						//1行目＝add_array
						///foreach($mapping_array[$ss_code1] as $number => $val){	//DM017,DM013・・・とかをループしてる
						for($number=0;$number<count($kasan_mst_array[$ss_code1]); $number++){

							$ss_obj = $kasan_mst_array[$ss_code1][$number];

							// 20101230 条件追加　同一部位であれば加算すること
							if($sisiki_list[$k] == $ss_obj->bui_code && !in_array($ss_obj->check_digit, $mapping_checked_list)){

								$map_kasan_code = $ss_obj->code;	//加算コード
								$map_kasan_num = $ss_obj->suryo;	//加算数量
								$map_kasan_kizami = $ss_obj->kizami;	//加算きざみ
                                $map_kasan_sort = $ss_obj->sort;    //加算表示順

								//加算コードの末尾に追加するために、最後の「空でない」要素の位置を取得
								$cnt = -1;
								foreach($kasan_array as $key => $val){
									if($val != '') continue;
									$cnt = $key;
									break;
								}

								if($cnt != -1){
									foreach($map_kasan_code as $key => $val){	// (ダミーだけど）DM013,EY999レベルのループ

										//加算コードを$add_arrayにセット↓
                                        $kasan_array[$cnt] = (object) array('code' => $val, 'sort' => $map_kasan_sort);  //KasanData扱いにしたいのですが・・

										//加算数量を$add_num_arrayにセット↓
										$kasan_suryo = $map_kasan_num[$key];

										if($map_kasan_kizami[$key] == 0){
											$kasan_suryo = 1;
											$kasan_num_array[$cnt] = '';
										}else{
											$kasan_num_array[$cnt] = $kasan_suryo;
										}

										if(!in_array($ss_obj->check_digit, $mapping_checked_list)){
											$tensu += $ss_obj->tensu * $kasan_suryo;
										}

										//使用済みマッピングを登録
										$mapping_checked_list[] = $ss_obj->check_digit;

										if($cnt > 34) break;
										else $cnt++;
									}
								}
							}
						}

						//2行目＝add_array_2

					}

				}

debug_dump($kasan_array, '$kasan_array');
debug_dump($kasan_num_array, '$kasan_num_array');

debug_dump($kasan_array_2,'$kasan_array_2');
debug_dump($kasan_num_array_2, '$kasan_num_array_2');


debug_dump($ss_add_exception_flag, '$ss_add_exception_flag');


				//2011 6.6 合算処理にも下記の「点数が違う場合コード」の出力が必要とのこと
				$extra_code = array();

				//もし医院が「補管」の届出を行っていなかった場合、その処置の「点数」欄と「点数70」欄の数値が違うかチェック。
				//違っていたら点数70を使用しているため、加算コードの末尾に「未届出減算AM004」を追加
				if($no_h_flg == 1){
                    if(($mst_tensu70 > 0 && $mst_tensu != $mst_tensu70)
                    ||  ($mst_tensu70_150 > 0 && $mst_tensu != $mst_tensu70_150)){
                        //加算コードの末尾に追加
                        $cnt = 0;
                        foreach ($kasan_array as $key => $val) {
                            if ($val != null) continue;
                            $cnt = $key;
                            break;
                        }
                        $kasan_array[$cnt] = (object) array('code' => GENSAN_CODE, 'sort' => 0);  //KasanData扱いにしたいのですが・・
                        $extra_code[] = GENSAN_CODE;
                    }
				}

				//REレコードにレセプト特記事項「40」を記録するか否かのフラグ
				$tmp_flg_40 = 0;

				//対象患者が６歳未満の場合、その処置の「点数」欄と「点数150」欄の数値が違うかチェック。
				//違っていたら点数150を使用しているため、加算コードを追加
				/*
				 * #課題 #2705 「6歳未満の場合の加算コード」と「困難(障)の場合の加算コード」記録
				 * 　昨年12月に修正戴きました「6歳未満の場合の加算コード」と「困難(障)の場合の加算コード」記録に関しまして、
				 * 　診療識別コードが44の場合は、処置マスタ詳細の(診療識別コード)だけではなく(分類)も参照頂かなくてはならなくなりました。
				 *
				 * 以下、最新仕様（2014/09/27時点）
				 * ■6歳未満の場合、下記加算コードを追加
				 * ・診療識別コード41,42,44(かつ分類9)の場合：AI001
				 * ・診療識別コード43,44(かつ分類10)の場合：AJ001
				 * ・診療識別コード54の場合：AK001
				 * ・診療識別コードが上記以外の場合：AM001
				 */
				debug_print('$age='.$age);
				debug_print('$mst_tensu150='.$mst_tensu150);
				debug_print('$s_db->get("TENSU", $k)='.$s_db->get("TENSU", $k));

				if($age < 6 && $mst_tensu != $mst_tensu150 && $s_db->get("TENSU", $k) == $mst_tensu150){
					debug_print("nyuyoji-kasan!");
					$tmp_flg_40 = 1;
					//加算コードの末尾に追加
					$cnt = 0;
					foreach($kasan_array as $key => $val){
						if($val != null) continue;
						$cnt = $key;
						break;
					}

					//「加算コード 乳幼児（M_SYOCHI_MASTER.KASAN_NYUYOJI）」の指定があれば
					if ($mst_kasan_nyuyoji != "") {
						$kasan_nyuyoji_arr = explode(',', $mst_kasan_nyuyoji);
						if (count($kasan_nyuyoji_arr) >= 1) {
							$code = $kasan_nyuyoji_arr[0];    //ss1なので1つ目をセット
						}
					} else {
						switch ($sikibetu_code) {
							case '41':
							case '42':
								$code = 'AI001';
								break;
							case '43':
							case '44':
								if ($bunrui == '9') {
									$code = 'AI001';
								} else if ($bunrui == '10') {
									$code = 'AJ001';
								}
								break;
							case '54':
								$code = 'AK001';
								break;
							default:
								$code = 'AM001';
						}
						//SS2～4にも引き継ぐため退避
						$extra_code_ss1to4 = $code;
					}
					if ($ss_add_exception_flag != 1) {
						$kasan_array[$cnt] = (object)array('code' => $code, 'sort' => 0);  //KasanData扱いにしたいのですが・・
					}
					$extra_code[] = $code;

				}

				//対象患者が困難（障）の場合、その処置の「点数」欄と「点数150」欄の数値が違うかチェック。
				//違っていたら点数150を使用しているため、加算コードを追加
				/*
				 * #課題 #2705 「6歳未満の場合の加算コード」と「困難(障)の場合の加算コード」記録
				 * 　昨年12月に修正戴きました「6歳未満の場合の加算コード」と「困難(障)の場合の加算コード」記録に関しまして、
				 * 　診療識別コードが44の場合は、処置マスタ詳細の(診療識別コード)だけではなく(分類)も参照頂かなくてはならなくなりました。
				 *
				 * 以下、最新仕様（2014/09/27時点）
				 * ■困難（障）の場合、下記加算コードを追加
				 * 診療識別コード41,42,44(分類9)の場合：AI002
				 * 診療識別コード43,44(分類10)の場合：AJ002
				 * 診療識別コード54の場合：AK002
				 * 診療識別コードが上記以外の場合：AM002
				 */
				if($konnan == 1 && $mst_tensu != $mst_tensu150) {
					$tmp_flg_40 = 1;
					//加算コードの末尾に追加
					$cnt = 0;
					foreach ($kasan_array as $key => $val) {
						if ($val != null) continue;
						$cnt = $key;
						break;
					}

					//「加算コード 困難(障)（M_SYOCHI_MASTER.KASAN_KONNAN）」の指定があれば
					if($mst_kasan_konnan != "") {
						$kasan_konnan_arr = explode(',',$mst_kasan_konnan);
						if(count($kasan_konnan_arr) >= 1){
							$code = $kasan_konnan_arr[0];	//ss1なので1つ目をセット
						}
					}else{
						switch ($sikibetu_code) {
							case '41':
							case '42':
								$code = 'AI002';
								break;
							case '43':
							case '44':
								if ($bunrui == '9') {
									$code = 'AI002';
								} else if ($bunrui == '10') {
									$code = 'AJ002';
								}
								break;
							case '54':
								$code = 'AK002';
								break;
							default:
								$code = 'AM002';
						}
						//SS2～4にも引き継ぐため退避
						$extra_code_ss1to4 = $code;
					}
					if ($ss_add_exception_flag != 1) {
						$kasan_array[$cnt] = (object)array('code' => $code, 'sort' => 0);  //KasanData扱いにしたいのですが・・
					}
					$extra_code[] = $code;
				}

				if($tmp_flg_40 == 1 || $houmon == 1) $flg_40 = true;


				//加算コード並び替え処理
				if($kasan_sort_flg == 1){
					$kasan_code_data = ajust_kasan_code_with_sort($kasan_array, $kasan_num_array);
					if($honsu_diff_result == KASAN_ALL_LARGE || $honsu_diff_result == KASAN_WITH_SMALL){
						$kasan_code_data_2 = ajust_kasan_code_with_sort($kasan_array_2, $kasan_num_array_2);
					}
				}else{
					$kasan_code_data = ajust_kasan_code_without_sort($kasan_array, $kasan_num_array);
					if($honsu_diff_result == KASAN_ALL_LARGE || $honsu_diff_result == KASAN_WITH_SMALL){
						$kasan_code_data_2 = ajust_kasan_code_without_sort($kasan_array_2, $kasan_num_array_2);
					}
				}
debug_dump($kasan_code_data,'$kasan_code_data');
debug_dump($kasan_code_data_2,'$kasan_code_data_2');

				//************************************************************************************
				//歯科診療行為レコード
				//************************************************************************************
				//#2643
				/*
				 * 診療行為＝加算
				 * 診療行為≠加算 かつ 診療行為＝１
				 */
				if($honsu_diff_result == HONSU_EQUAL || $honsu_diff_result == HONSU_1){
					$ss_arr = array();
					$ss_arr[] = SS_CODE;
					$ss_arr[] = $sikibetu_code;		//診療識別
					$ss_arr[] = $futan_type;		//負担区分
					$ss_arr[] = $ss_code1;			//診療行為コード1
					($ss_kizami1 == 1) ? $ss_arr[] = $ss_kizami_suryo1 : $ss_arr[] = '';		//診療行為数量データ1
					$ss_arr[] = '';					//診療行為数量データ2

					//加算コードの配列を追加
					for($l=0; $l<35; $l++){
						//$ss_arr[] = $add_array[$l];
						//$ss_arr[] = $add_num_array[$l];
                            $ss_arr[] = $kasan_code_data[$l]['kasan_code'];
						$ss_arr[] = $kasan_code_data[$l]['kasan_num'];
					}

					//最終レコードの場合、点数と回数を記録
					if($last_flg == 1){
						$ss_arr[] = $tensu;			//点数
						$ss_arr[] = $honsu;			//回数
					}else{
						$ss_arr[] = '';				//点数
						$ss_arr[] = '';				//回数
					}

					//1-31日の情報
					$jyushin_day = (int)substr($jyushin_date, -2);
					for($l=0; $l<31; $l++) ($jyushin_day == $l+1 && $miraiin_flg == 0) ? $ss_arr[] = $honsu : $ss_arr[] = '';

					$tmp_csv .= create_csv($ss_arr);

				/*
				 * パターンB
				 * 診療行為＜加算
				 */
				}else if($honsu_diff_result == KASAN_ALL_LARGE){


					if($is_baisu && $kasan_num == 1){

						//1行目
						$ss_arr = array();
						$ss_arr[] = SS_CODE;
						$ss_arr[] = $sikibetu_code;		//診療識別
						$ss_arr[] = $futan_type;		//負担区分
						$ss_arr[] = $ss_code1;			//診療行為コード1
						($ss_kizami1 == 1) ? $ss_arr[] = $ss_kizami_suryo1 : $ss_arr[] = '';		//診療行為数量データ1
						$ss_arr[] = '';					//診療行為数量データ2

						//加算コードの配列を追加
						for($l=0; $l<35; $l++){
							//$ss_arr[] = $add_array[$l];
							//$ss_arr[] = $add_num_array[$l];
							$ss_arr[] = $kasan_code_data[$l]['kasan_code'];
							$ss_arr[] = $kasan_code_data[$l]['kasan_num'];
						}

						//最終レコードの場合、点数と回数を記録
						if($last_flg == 1){
							$ss_arr[] = $tensu;			//点数
							$ss_arr[] = $honsu;		//回数
						}else{
							$ss_arr[] = '';				//点数
							$ss_arr[] = '';				//回数
						}

						//1-31日の情報
						$jyushin_day = (int)substr($jyushin_date, -2);
						for($l=0; $l<31; $l++) ($jyushin_day == $l+1 && $miraiin_flg == 0) ? $ss_arr[] = $honsu : $ss_arr[] = '';

						$tmp_csv .= create_csv($ss_arr);

					}else{

						//1行目
						$ss_arr = array();
						$ss_arr[] = SS_CODE;
						$ss_arr[] = $sikibetu_code;		//診療識別
						$ss_arr[] = $futan_type;		//負担区分
						$ss_arr[] = $ss_code1;			//診療行為コード1
						($ss_kizami1 == 1) ? $ss_arr[] = $ss_kizami_suryo1 : $ss_arr[] = '';		//診療行為数量データ1
						$ss_arr[] = '';					//診療行為数量データ2

						//加算コードの配列を追加
						for($l=0; $l<35; $l++){
							//$ss_arr[] = $add_array[$l];
							//$ss_arr[] = $add_num_array[$l];
							$ss_arr[] = $kasan_code_data[$l]['kasan_code'];
							$ss_arr[] = $kasan_code_data[$l]['kasan_num'];
						}

						//最終レコードの場合、点数と回数を記録
						if($last_flg == 1){
							$ss_arr[] = $tensu;			//点数
							$ss_arr[] = $honsu - 1;		//回数
						}else{
							$ss_arr[] = '';				//点数
							$ss_arr[] = '';				//回数
						}

						//1-31日の情報
						$jyushin_day = (int)substr($jyushin_date, -2);
						for($l=0; $l<31; $l++) ($jyushin_day == $l+1 && $miraiin_flg == 0) ? $ss_arr[] = $honsu - 1 : $ss_arr[] = '';

						$tmp_csv .= create_csv($ss_arr);


						//2行目
						$ss_arr_2 = array();
						$ss_arr_2[] = SS_CODE;
						$ss_arr_2[] = $sikibetu_code;		//診療識別
						$ss_arr_2[] = $futan_type;		//負担区分
						$ss_arr_2[] = $ss_code1;			//診療行為コード1
						($ss_kizami1 == 1) ? $ss_arr_2[] = $ss_kizami_suryo1 : $ss_arr_2[] = '';		//診療行為数量データ1
						$ss_arr_2[] = '';					//診療行為数量データ2

						//加算コードの配列を追加
						for($l=0; $l<35; $l++){
							$ss_arr_2[] = $kasan_code_data_2[$l]['kasan_code'];
							$ss_arr_2[] = $kasan_code_data_2[$l]['kasan_num'];
						}

						//最終レコードの場合、点数と回数を記録
						if($last_flg == 1){
							$ss_arr_2[] = $tensu_2;			//点数
							$ss_arr_2[] = 1;				//回数
						}else{
							$ss_arr_2[] = '';				//点数
							$ss_arr_2[] = '';				//回数
						}

						//1-31日の情報
						$jyushin_day = (int)substr($jyushin_date, -2);
						for($l=0; $l<31; $l++) ($jyushin_day == $l+1 && $miraiin_flg == 0) ? $ss_arr_2[] = 1 : $ss_arr_2[] = '';

						$tmp_csv_2 .= create_csv($ss_arr_2);
					}

				/*
				 * パターンA
				 * 診療行為＞加算 かつ 複数加算なし
				 */
				}else if($honsu_diff_result == KASAN_WITH_SMALL && $kasan_num == 1){

					//1行目
					$ss_arr = array();
					$ss_arr[] = SS_CODE;
					$ss_arr[] = $sikibetu_code;		//診療識別
					$ss_arr[] = $futan_type;		//負担区分
					$ss_arr[] = $ss_code1;			//診療行為コード1
					($ss_kizami1 == 1) ? $ss_arr[] = $ss_kizami_suryo1 : $ss_arr[] = '';		//診療行為数量データ1
					$ss_arr[] = '';					//診療行為数量データ2

					//加算コードの配列を追加
					for($l=0; $l<35; $l++){
						//$ss_arr[] = $add_array[$l];
						//$ss_arr[] = $add_num_array[$l];
						$ss_arr[] = $kasan_code_data[$l]['kasan_code'];
						$ss_arr[] = $kasan_code_data[$l]['kasan_num'];
					}

					//最終レコードの場合、点数と回数を記録
					if($last_flg == 1){
						$ss_arr[] = $tensu;			//点数
						$ss_arr[] = $kasan_org_honsu;	//回数
					}else{
						$ss_arr[] = '';				//点数
						$ss_arr[] = '';				//回数
					}

					//1-31日の情報
					$jyushin_day = (int)substr($jyushin_date, -2);
					for($l=0; $l<31; $l++) ($jyushin_day == $l+1 && $miraiin_flg == 0) ? $ss_arr[] = $kasan_org_honsu : $ss_arr[] = '';

					$tmp_csv .= create_csv($ss_arr);


					//2行目
					$ss_arr_2 = array();
					$ss_arr_2[] = SS_CODE;
					$ss_arr_2[] = $sikibetu_code;		//診療識別
					$ss_arr_2[] = $futan_type;		//負担区分
					$ss_arr_2[] = $ss_code1;			//診療行為コード1
					($ss_kizami1 == 1) ? $ss_arr_2[] = $ss_kizami_suryo1 : $ss_arr_2[] = '';		//診療行為数量データ1
					$ss_arr_2[] = '';					//診療行為数量データ2

					//加算コードの配列を追加
					for($l=0; $l<35; $l++){
						$ss_arr_2[] = $kasan_code_data_2[$l]['kasan_code'];
						$ss_arr_2[] = $kasan_code_data_2[$l]['kasan_num'];
					}

					//最終レコードの場合、点数と回数を記録
					if($last_flg == 1){
						$ss_arr_2[] = $tensu_2;			//点数
						$ss_arr_2[] = $honsu - $kasan_org_honsu;	//回数
					}else{
						$ss_arr_2[] = '';				//点数
						$ss_arr_2[] = '';				//回数
					}

					//1-31日の情報
					$jyushin_day = (int)substr($jyushin_date, -2);
					for($l=0; $l<31; $l++) ($jyushin_day == $l+1 && $miraiin_flg == 0) ? $ss_arr_2[] = $honsu - $kasan_org_honsu : $ss_arr_2[] = '';

					$tmp_csv_2 .= create_csv($ss_arr_2);


				/*
				 * パターンC
				 * 診療行為＞加算 かつ 複数加算あり
				 */
				}else if($honsu_diff_result == KASAN_WITH_SMALL && $kasan_num > 1){

					//1行目
					$ss_arr = array();
					$ss_arr[] = SS_CODE;
					$ss_arr[] = $sikibetu_code;		//診療識別
					$ss_arr[] = $futan_type;		//負担区分
					$ss_arr[] = $ss_code1;			//診療行為コード1
					($ss_kizami1 == 1) ? $ss_arr[] = $ss_kizami_suryo1 : $ss_arr[] = '';		//診療行為数量データ1
					$ss_arr[] = '';					//診療行為数量データ2

					//加算コードの配列を追加
					for($l=0; $l<35; $l++){
						//$ss_arr[] = $add_array[$l];
						//$ss_arr[] = $add_num_array[$l];
						$ss_arr[] = $kasan_code_data[$l]['kasan_code'];
						$ss_arr[] = $kasan_code_data[$l]['kasan_num'];
					}

					//最終レコードの場合、点数と回数を記録
					if($last_flg == 1){
						$ss_arr[] = $tensu;			//点数
						$ss_arr[] = 1;				//回数
					}else{
						$ss_arr[] = '';				//点数
						$ss_arr[] = '';				//回数
					}

					//1-31日の情報
					$jyushin_day = (int)substr($jyushin_date, -2);
					for($l=0; $l<31; $l++) ($jyushin_day == $l+1 && $miraiin_flg == 0) ? $ss_arr[] = 1 : $ss_arr[] = '';

					$tmp_csv .= create_csv($ss_arr);


					//2行目
					$ss_arr_2 = array();
					$ss_arr_2[] = SS_CODE;
					$ss_arr_2[] = $sikibetu_code;		//診療識別
					$ss_arr_2[] = $futan_type;		//負担区分
					$ss_arr_2[] = $ss_code1;			//診療行為コード1
					($ss_kizami1 == 1) ? $ss_arr_2[] = $ss_kizami_suryo1 : $ss_arr_2[] = '';		//診療行為数量データ1
					$ss_arr_2[] = '';					//診療行為数量データ2

					//加算コードの配列を追加
					for($l=0; $l<35; $l++){
						$ss_arr_2[] = $kasan_code_data_2[$l]['kasan_code'];
						$ss_arr_2[] = $kasan_code_data_2[$l]['kasan_num'];
					}

					//最終レコードの場合、点数と回数を記録
					if($last_flg == 1){
						$ss_arr_2[] = $tensu_2;			//点数
						$ss_arr_2[] = $honsu-1;			//回数
					}else{
						$ss_arr_2[] = '';				//点数
						$ss_arr_2[] = '';				//回数
					}

					//1-31日の情報
					$jyushin_day = (int)substr($jyushin_date, -2);
					for($l=0; $l<31; $l++) ($jyushin_day == $l+1 && $miraiin_flg == 0) ? $ss_arr_2[] = $honsu-1 : $ss_arr_2[] = '';

					$tmp_csv_2 .= create_csv($ss_arr_2);

				}


				//加算用診療行為で加算されているかもしれないためマスターデータ更新（点数のぞく）
				$mst['honsu'] = $honsu;
				$mst['sikibetu_code'] = $sikibetu_code;
				$mst['futan_type'] = $futan_type;
				$mst['iy_code'] = $iy_code1;
				$mst['iy_shiyoryo_kisai'] = $iy_shiyoryo_kisai1;
				$mst['iy_shiyoryo'] = $iy_shiyoryo1;
				$mst['jyushin_date'] = $jyushin_date;
				$mst['miraiin_flg'] = $miraiin_flg;


				//合算歯科診療行為レコード2
				if($ss_code2 != ''){
					--$record_num;
					if($record_num == 0) $last_flg = 1;

					//乳幼児加算、困難（障）加算 #3930
					$extra_code = array();
					if($extra_code_ss1to4 != ""){
						$extra_code[] = $extra_code_ss1to4;	//退避したコードを代入
					}
					if(count($kasan_nyuyoji_arr) >= 2){
						$code = $kasan_nyuyoji_arr[1];	//ss2なので2つ目をセット
						$extra_code[] = $code;
					}
					if(count($kasan_konnan_arr) >= 2){
						$code = $kasan_konnan_arr[1];	//ss2なので2つ目をセット
						$extra_code[] = $code;
					}
//					debug_dump($kasan_mst_array,'$kasan_mst_array');
//					debug_dump($extra_code,'$extra_code');
					$tmp_csv .= create_csv(create_ss($syochiMaster[$syochi_id][$dataKey], $s_db, $k, $mst, $last_flg, 2, $kasan_mst_array, $extra_code, $tensu, $sisiki_list, $mapping_checked_list));
				}

				//合算歯科診療行為レコード3
				if($ss_code3 != ''){
					--$record_num;
					if($record_num == 0) $last_flg = 1;

					//乳幼児加算、困難（障）加算 #3930
					$extra_code = array();
					if($extra_code_ss1to4 != ""){
						$extra_code[] = $extra_code_ss1to4;	//退避したコードを代入
					}
					if(count($kasan_nyuyoji_arr) >= 3){
						$code = $kasan_nyuyoji_arr[2];	//ss3なので3つ目をセット
						$extra_code[] = $code;
					}
					if(count($kasan_konnan_arr) >= 3){
						$code = $kasan_konnan_arr[2];	//ss3なので3つ目をセット
						$extra_code[] = $code;
					}
					$tmp_csv .= create_csv(create_ss($syochiMaster[$syochi_id][$dataKey], $s_db, $k, $mst, $last_flg, 3, $kasan_mst_array, $extra_code, $tensu, $sisiki_list, $mapping_checked_list));
				}

				//合算歯科診療行為レコード4
				if($ss_code4 != ''){
					--$record_num;
					if($record_num == 0) $last_flg = 1;

					//乳幼児加算、困難（障）加算 #3930
					$extra_code = array();
					if($extra_code_ss1to4 != ""){
						$extra_code[] = $extra_code_ss1to4;	//退避したコードを代入
					}
					if(count($kasan_nyuyoji_arr) >= 4){
						$code = $kasan_nyuyoji_arr[3];	//ss4なので4つ目をセット
						$extra_code[] = $code;
					}
					if(count($kasan_konnan_arr) >= 4){
						$code = $kasan_konnan_arr[3];	//ss4なので4つ目をセット
						$extra_code[] = $code;
					}
					$tmp_csv .= create_csv(create_ss($syochiMaster[$syochi_id][$dataKey], $s_db, $k, $mst, $last_flg, 4, $kasan_mst_array, $extra_code, $tensu, $sisiki_list, $mapping_checked_list));
				}

				//点数は合算歯科診療行為レコード内で更新されている可能性がある。
				//参照渡しで対処
				$mst['tensu'] = $tensu;

				//合算医科診療行為レコード
				if($si_code != ''){
					--$record_num;
					if($record_num == 0) $last_flg = 1;
					$tmp_csv .= create_csv(create_si($syochiMaster[$syochi_id][$dataKey], $s_db, $k, $mst, $last_flg, 0));
				}

				//合算医薬品レコード
				if($iy_code1 != ''){
					--$record_num;
					if($record_num == 0) $last_flg = 1;
					//合剤処理を考える必要がない？ 最終引数に合算医薬品レコードのフラグを立てた。合算処理として存在するかは不明…
					$tmp_csv .= create_csv(create_iy($syochiMaster[$syochi_id][$dataKey], $s_db, $k, $mst, $last_flg, 0, 1));
				}

				//合算特定器材レコード
				if($to_code != ''){
					--$record_num;
					if($record_num == 0) $last_flg = 1;
					$tmp_csv .= create_csv(create_to($syochiMaster[$syochi_id][$dataKey], $s_db, $k, $mst, $last_flg, 0));
				}

				$syoche_sort_array[$patient_id][$henrei_flg][$saiseikyu][$sikibetu_code][] = $tmp_csv;

				if($honsu_diff_result == KASAN_ALL_LARGE || $honsu_diff_result == KASAN_WITH_SMALL){
					$syoche_sort_array[$patient_id][$henrei_flg][$saiseikyu][$sikibetu_code][] = $tmp_csv_2;
				}
			}

			//////////////////////////////////////////////////////////////////////////////////////
			//  SS以外の処置の場合
			//////////////////////////////////////////////////////////////////////////////////////

			//************************************************************************************
			//医科診療行為レコード
			//************************************************************************************
			foreach($si_data as $k){

				//処置IDを元に処置の詳細情報を取得
				$syochi_id = $s_db->get("SYOCHI_ID", $k);	//処置ID
/*				$dbo = new EzDBMulti($DB_NAME);
				$sql  = "SELECT * FROM ".MASTER_DB.".M_SYOCHI_MASTER WHERE ID = ".$syochi_id;
				$sql .= " AND DATA_KIGEN_S <= '".$jyushin_date."' AND DATA_KIGEN_E >= '".$jyushin_date."' ";
				$sql .= " LIMIT 0, 1 ";
				$dbo->sql($sql);
*/
				//マスタデータ
				$mst['futan_type'] = $futan_type;
//				$mst['sikibetu_code'] = $dbo->get("SIKIBETU_CODE", 0);
				$mst['sikibetu_code'] = $syochiMaster[$syochi_id][$dataKey]['SIKIBETU_CODE'];
				$mst['tensu'] = $s_db->get("TENSU", $k);
				$mst['jyushin_date'] = $jyushin_date;
				$mst['miraiin_flg'] = $miraiin_flg;

				//もし数量データが空欄の場合には、算定数を記録
				//$si_suryo  = $dbo->get("SI_SURYO", 0);
				//$honsu = $s_db->get("HONSU", $k);			//本数
				//if($si_suryo == '') $si_suryo = $honsu;

				$syoche_sort_array[$patient_id][$henrei_flg][$saiseikyu][$mst['sikibetu_code']][] = create_gassan_si($syochiMaster[$syochi_id][$dataKey], $s_db, $k, $mst);
			}

			//************************************************************************************
			//医薬品レコード
			//************************************************************************************
			//医薬品処置を用法・本数でグループ分けして、連想配列に格納。
			//用法および算定数（日数）(HONSU)が同じ２種類以上の薬剤に関しては「合剤」とする

			//想定している配列構造は下記の内容
			//　$iy_data[$yakuzai_group][$youhou][$honsu][] = $syochi_id;
			// yakuzai_groupが0は薬剤区分5,6,7（単剤オンリー）、yakuzai_groupが1は薬剤区分1　yakuzai_groupが2は薬剤区分4→区分2,3は単剤扱いになった
			// yakuzai_groupが0なら強制的に単剤フロー。yakuzai_groupが1か2なら同用法・同算定数のものが複数あれば合剤処理
			// 2011/04/13 竹尾様と電話にて。単剤処理に関しては点数がゼロならIY出力をスキップ。
			// 合剤処理に関しては、yakuzai_groupが1のものは処方せんが算定されていた場合はIY出力をスキップ。
			// yakuzai_groupが2のものは処方せんが算定されていてもIY出力。
			foreach($iy_data as $yakuzai_key => $tmp_arr0){
				foreach($tmp_arr0 as $youhou_key => $tmp_arr1){
					foreach($tmp_arr1 as $honsu_key => $tmp_arr2){
						//合剤
						if(count($tmp_arr2) > 1 && ($yakuzai_key == 1 || $yakuzai_key == 2)){

							//もし処方せんが算定されている、かつ、yakuzai_groupが1（薬剤区分1,2,3）なら処理をスキップ
							if($syoho_flg && $yakuzai_key == '1') continue;

							$gozai_num = count($tmp_arr2);
							$count = 0;
							$gouzai_tensu = 0;
							$tmp = '';
							foreach($tmp_arr2 as $k => $syochi_id){
								$count++;

								//もし数量データが空欄の場合には、算定数を記録
								$honsu = $s_db->get("HONSU", $k);			//本数
								$youhou = $s_db->get("YOUHOU", $k);			//用法
								//$ikkairyou = $s_db->get("IKKAIRYOU", $k);	//一回量

								$mst['sikibetu_code'] = $syochiMaster[$syochi_id][$dataKey]['SIKIBETU_CODE'];
								$mst['iy_code'] = $syochiMaster[$syochi_id][$dataKey]['IY_CODE1'];
								$mst['iy_shiyoryo_kisai'] = $syochiMaster[$syochi_id][$dataKey]['IY_SHIYORYO_KISAI1'];
								$mst['iy_shiyoryo'] = $syochiMaster[$syochi_id][$dataKey]['IY_SHIYORYO1'];

								$gouzai_tensu += $s_db->get("TENSU", $k);
								$mst['tensu'] = $gouzai_tensu;
								$mst['jyushin_date'] = $jyushin_date;
								$mst['miraiin_flg'] = $miraiin_flg;

								//合剤の場合、最初のレコードのみ診療識別コードあり
								($count == 1) ? $first_flg = 1 : $first_flg = 0;
								($gozai_num == $count) ? $last_flg = 1 : $last_flg = 0;

//								$syoche_sort_array[$patient_id][$henrei_flg][$mst['sikibetu_code']][] = create_csv(create_iy($dbo, $s_db, $k, $mst, $last_flg, $first_flg));
								$tmp .= create_csv(create_iy($syochiMaster[$syochi_id][$dataKey], $s_db, $k, $mst, $last_flg, $first_flg));
							}
debug_print('$tmp=>'.$tmp);
							$syoche_sort_array[$patient_id][$henrei_flg][$saiseikyu][$mst['sikibetu_code']][] = $tmp;

						//単剤
						}else{
							foreach($tmp_arr2 as $k => $syochi_id){

								$mst['sikibetu_code'] = $syochiMaster[$syochi_id][$dataKey]['SIKIBETU_CODE'];
								$mst['iy_code'] = $syochiMaster[$syochi_id][$dataKey]['IY_CODE1'];
								$mst['iy_shiyoryo_kisai'] = $syochiMaster[$syochi_id][$dataKey]['IY_SHIYORYO_KISAI1'];
								$mst['iy_shiyoryo'] = $syochiMaster[$syochi_id][$dataKey]['IY_SHIYORYO1'];
								$iy_code2 = $syochiMaster[$syochi_id][$dataKey]['IY_CODE2'];

								//もし数量データが空欄の場合には、算定数を記録
								$honsu = $s_db->get("HONSU", $k);			//本数
								$youhou = $s_db->get("YOUHOU", $k);			//用法
								$ikkairyou = $s_db->get("IKKAIRYO", $k);	//一回量

								$mst['tensu'] = $s_db->get("TENSU", $k);
								$mst['jyushin_date'] = $jyushin_date;
								$mst['miraiin_flg'] = $miraiin_flg;

								//点数がゼロならIY出力をスキップ
								if($mst['tensu'] != 0){

									//単剤の場合、(麻酔薬でない限り)単一レコードのため、最終レコードとして点数と回数を記録
									$tmp_iy_data = '';
									$last_flg = 1;
									if($iy_code2 != '') $last_flg = 0;
									$tmp_iy_data .= create_csv(create_iy($syochiMaster[$syochi_id][$dataKey], $s_db, $k, $mst, $last_flg, 1));

									if($iy_code2 != ''){
										$mst['iy_code'] = $iy_code2;
										$mst['iy_shiyoryo_kisai'] = $syochiMaster[$syochi_id][$dataKey]['IY_SHIYORYO_KISAI2'];
										$mst['iy_shiyoryo'] = $syochiMaster[$syochi_id][$dataKey]['IY_SHIYORYO2'];

										$last_flg = 1;
										$tmp_iy_data .= create_csv(create_iy($syochiMaster[$syochi_id][$dataKey], $s_db, $k, $mst, $last_flg, 0));
									}
debug_print('$tmp_iy_data=>'.$tmp_iy_data);
									$syoche_sort_array[$patient_id][$henrei_flg][$saiseikyu][$mst['sikibetu_code']][] = $tmp_iy_data;
								}
							}
						}
					}
				}
			}

			//************************************************************************************
			//特定器材レコード
			//************************************************************************************
			foreach($to_data as $k){
				$last_flg = 1;

				//処置IDを元に処置の詳細情報を取得
				$syochi_id = $s_db->get("SYOCHI_ID", $k);	//処置ID
/*				$dbo = new EzDBMulti($DB_NAME);
				$sql  = "SELECT * FROM ".MASTER_DB.".M_SYOCHI_MASTER WHERE ID = ".$syochi_id;
				$sql .= " AND DATA_KIGEN_S <= '".$jyushin_date."' AND DATA_KIGEN_E >= '".$jyushin_date."' ";
				$sql .= " LIMIT 0, 1 ";
				$dbo->sql($sql);
*/
				//マスタデータ
				$mst['futan_type'] = $futan_type;
//				$mst['sikibetu_code'] = $dbo->get("SIKIBETU_CODE", 0);
				$mst['sikibetu_code'] = $syochiMaster[$syochi_id][$dataKey]['SIKIBETU_CODE'];
				$mst['tensu'] = $s_db->get("TENSU", $k);
				$mst['jyushin_date'] = $jyushin_date;
				$mst['miraiin_flg'] = $miraiin_flg;

				//もし使用量が空欄の場合には、算定数を記録
				$honsu = $s_db->get("HONSU", $k);			//本数
//				$to_siyoryo = $dbo->get("TO_SIYORYO", 0);
				$to_siyoryo = $syochiMaster[$syochi_id][$dataKey]['TO_SIYORYO'];
				if($to_siyoryo == '') $to_siyoryo = $honsu;
				$syoche_sort_array[$patient_id][$henrei_flg][$saiseikyu][$mst['sikibetu_code']][] = create_csv(create_to($syochiMaster[$syochi_id][$dataKey], $s_db, $k, $mst, $last_flg));
			}


			//************************************************************************************
			//コメントレコード
			//************************************************************************************
			foreach($co_data as $k){

				//処置IDを元に処置の詳細情報を取得
				$syochi_id = $s_db->get("SYOCHI_ID", $k);	//処置ID
/*				$dbo = new EzDBMulti($DB_NAME);
				$sql  = "SELECT * FROM ".MASTER_DB.".M_SYOCHI_MASTER WHERE ID = ".$syochi_id;
				$sql .= " AND DATA_KIGEN_S <= '".$jyushin_date."' AND DATA_KIGEN_E >= '".$jyushin_date."' ";
				$sql .= " LIMIT 0, 1 ";
				$dbo->sql($sql);

				$sikibetu_code = $dbo->get("SIKIBETU_CODE", 0);
				$co_sisiki_flg = $dbo->get("CO_SISIKI_FLG", 0);
				$m_syochi_id   = $dbo->get("ID", 0);
				$co_code       = $dbo->get("CO_CODE", 0);
				$syochi_name1  = $dbo->get("SYOCHI_NAME1", 0);
*/
				$sikibetu_code = $syochiMaster[$syochi_id][$dataKey]['SIKIBETU_CODE'];
				$co_sisiki_flg = $syochiMaster[$syochi_id][$dataKey]['CO_SISIKI_FLG'];
				$m_syochi_id = $syochiMaster[$syochi_id][$dataKey]['ID'];
				$co_code = $syochiMaster[$syochi_id][$dataKey]['CO_CODE'];
				$syochi_name1 = $syochiMaster[$syochi_id][$dataKey]['SYOCHI_NAME1'];

				$comment_data = '';
				$syochi_name = $s_db->get("SYOCHI_NAME", $k);
				//任意の文字列入力
				if($co_code == '810000001'){
					$comment_data = $s_db->get("SYOCHI_NAME", $k);			//処置名
				//定型コメントの場合
				}else if(mb_substr($co_code,0,3) == '820'){
					//$comment_data = $syochi_name1;
					$comment_data = '';
				//一部定型コメントの場合
				}else if($co_code == '830000040'){
					$comment_data = preg_replace("/(.*)除去/u", "$1", $syochi_name);
					$comment_data = str_replace('（', '', $comment_data);
					$comment_data = str_replace('）', '', $comment_data);
					//$comment_data = str_replace('除去', '', $syochi_name);
				//定型コメント文に一部数字情報を入力するものの場合
				}else if(mb_substr($co_code,0,3) == '840'){
					switch($m_syochi_id){
						case '1601':
							$comment_data = '１';
							break;

						case '2089':
							$comment_data = preg_replace("/^[^0-9０-９]*([0-9０-９]+?)[^0-9０-９]*$/u", "$1", $syochi_name);
							$comment_data = mb_convert_kana(trim($comment_data), "n", SYS_ENCODE);
							$comment_data = mb_convert_kana($comment_data, "N", SYS_ENCODE);
							break;

						case '2092':
						case '2093':
						case '2016':
						case '2017':
						case '2094':
						case '2095':
						case '2096':
						case '2101':
						case '1768':
						case '2102':
						case '2103':
							$comment_data = preg_replace("/^[^0-9０-９]*([0-9０-９]+?)[^0-9０-９]*$/u", "$1", $syochi_name);
							$comment_data = mb_convert_kana(trim($comment_data), "n", SYS_ENCODE);
							$comment_data = mb_convert_kana(sprintf("%02d", $comment_data), "N", SYS_ENCODE);

							//$comment_data = mb_convert_kana(sprintf("%02d", trim($comment_data)), "N", SYS_ENCODE);
							//$comment_data = mb_convert_kana(trim($comment_data), "N", SYS_ENCODE);
							break;

						case '1613':
						case '2090':
						case '2091':
							$tmp = preg_replace("/^[^0-9０-９]*([0-9０-９]+)月[^0-9０-９]*([0-9０-９]+)[^0-9０-９]*$/u", "$1,$2", $syochi_name);
							$tmp_str = explode(',', mb_convert_kana($tmp, "n", SYS_ENCODE));
							$comment_data = mb_convert_kana(sprintf("%02d", $tmp_str[0]).sprintf("%02d", $tmp_str[1]), "N", SYS_ENCODE);
							break;

						case '1610':
							$tmp = preg_replace("/^[^0-9０-９]*([0-9０-９]+)年[^0-9０-９]*([0-9０-９]+)[^0-9０-９]*$/u", "$1,$2", $syochi_name);
							$tmp_str = explode(',', mb_convert_kana($tmp, "n", SYS_ENCODE));
							$comment_data = mb_convert_kana(sprintf("%02d", $tmp_str[0]).sprintf("%02d", $tmp_str[1]), "N", SYS_ENCODE);
							break;

						case '2098':
						case '2099':
						case '2100':
							$tmp = preg_replace("/^[^0-9０-９]*([0-9０-９]+)年[^0-9０-９]*([0-9０-９]+)月[^0-9０-９]*([0-9０-９]+)[^0-9０-９]*$/u", "$1,$2,$3", $syochi_name);
							$tmp_str = explode(',', mb_convert_kana($tmp, "n", SYS_ENCODE));
							$comment_data = mb_convert_kana(sprintf("%02d", $tmp_str[0]).sprintf("%02d", $tmp_str[1]).sprintf("%02d", $tmp_str[2]), "N", SYS_ENCODE);
							break;

						case '2097':
							$tmp = preg_replace("/^[^0-9０-９]*([0-9０-９]+)時[^0-9０-９]*([0-9０-９]+)分[^0-9０-９]*([0-9０-９]+)時[^0-9０-９]*([0-9０-９]+)分[^0-9０-９]*$/u", "$1,$2,$3,$4", $syochi_name);
							$tmp_str = explode(',', mb_convert_kana($tmp, "n", SYS_ENCODE));
							$comment_data = mb_convert_kana(sprintf("%02d", $tmp_str[0]).sprintf("%02d", $tmp_str[1]).sprintf("%02d", $tmp_str[2]).sprintf("%02d", $tmp_str[3]), "N", SYS_ENCODE);
							break;

						default:
							$comment_data = '';
					}
				}
				//コメントデータはすべて全角文字に変換
				$comment_data = mb_convert_kana($comment_data, "AKNRSV", SYS_ENCODE);
				$sisiki_code = $sisiki_list[$k];

				$co_arr = array();
				$co_arr[] = CO_CODE;
				$co_arr[] = $sikibetu_code;		//診療識別
				$co_arr[] = $futan_type;		//負担区分
				$co_arr[] = $co_code;
				$co_arr[] = $comment_data;		//文字データ
				($co_sisiki_flg == 1) ? $co_arr[] = $sisiki_code : $co_arr[] = '';		//歯式（コメント）
				$co_arr[] = '';			//予備
				$co_arr[] = '';			//予備
				$co_arr[] = '';			//予備
				$co_arr[] = '';			//予備
				$co_arr[] = '';			//予備

				$syoche_sort_array[$patient_id][$henrei_flg][$saiseikyu][$sikibetu_code][] = create_csv($co_arr);
			}
		}


		//************************************************************************************
		//日計表レコード
		//************************************************************************************
		//$hs_arr = array();
		//$hs_arr[] = NI_CODE;

		//************************************************************************************
		//症状詳記レコード
		//************************************************************************************
		//$hs_arr = array();
		//$hs_arr[] = SJ_CODE;


		//公費レコードの場合、公費負担金から総合計点数計算？
	}
	//カルテの回数分出力する　終わり


        /* REレコード 13.区分コード、
      * 「REレコード(13)標準負担額区分」に「3」や「1」はHOレコード「(11)負担金額・医療保険」に値があれば、値をセットする
         */
        $HO_parts = explode(',', $HOcsv);
        if(strlen($HO_parts[10]) > 0){
            $RE_parts = explode(',', $REcsv);
            switch ($kogaku_kubun){
                case 0:
                    break;
                case 1:
                    $RE_parts[12] = '3';    //13.区分コード＝3
                    break;
                case 2:
                    $RE_parts[12] = '1';    //13.区分コード＝1
                    break;
                default:
                    break;
            }
            $REcsv = implode(',', $RE_parts);
        }
        
        /* REレコード 14.特記事項は「(11)負担金額・医療保険」に値があるかどうかは判断しない
	//「REレコード(14)レセプト特記事項」に40の記録もある場合は、4029ではなく2940というように数字の若い順にする
         */
        $HO_parts = explode(',', $HOcsv);
            $RE_parts = explode(',', $REcsv);
            switch ($kogaku_kubun){
                case 101:
                    $RE_parts[13] = '26'.$RE_parts[13];    //14.特記事項＝26
                    break;
                case 102:
                    $RE_parts[13] = '27'.$RE_parts[13];    //14.特記事項＝27
                    break;
                case 103:
                    $RE_parts[13] = '28'.$RE_parts[13];    //14.特記事項＝28
                    break;
                case 104:
                    $RE_parts[13] = '29'.$RE_parts[13];    //14.特記事項＝29
                    break;
                case 105:
                    $RE_parts[13] = '30'.$RE_parts[13];    //14.特記事項＝30
                    break;
                case 1:
                    $RE_parts[13] = '30'.$RE_parts[13];    //14.特記事項＝30
                    break;
                case 2:
                    $RE_parts[13] = '30'.$RE_parts[13];    //14.特記事項＝30
                    break;
                default:
                    break;
            }
            $REcsv = implode(',', $RE_parts);

		//高額療養費の「認定書なしまたは区分I・IV」にチェックが入っていて、年齢が70歳以上(70歳誕生日月を除く)で、保険者番号(HOKENJA_NO)欄に入力があり、負担割合(FUTAN_WARIAI)欄が1割または2割の場合：レセプトのREレコード14項目（レセプト特記事項）に「29」を記録する。
		if($kogaku_kubun == 0 && $age >= 70 && $hokenja_no != '' && $futan_wariai_org == 1){
			$RE_parts = explode(',', $REcsv);
			$RE_parts[13] .= '29';
			$REcsv = implode(',', $RE_parts);
		}

		if($kogaku_kubun == 0 && $age >= 70 && $hokenja_no != '' && $futan_wariai_org == 2){
			$RE_parts = explode(',', $REcsv);
			$RE_parts[13] .= '29';
			$REcsv = implode(',', $RE_parts);
		}

		//高額療養費の「認定書なしまたは区分I・IV」にチェックが入っていて、年齢が70歳以上(70歳誕生日月を除く)で、保険者番号(HOKENJA_NO)欄に入力があり、負担割合(FUTAN_WARIAI)欄が3割の場合：レセプトのREレコード14項目（レセプト特記事項）に「26」を記録する。

		if($kogaku_kubun == 0 && $age >= 70 && $hokenja_no != '' && $futan_wariai_org == 3){
			$RE_parts = explode(',', $REcsv);
			$RE_parts[13] .= '26';
			$REcsv = implode(',', $RE_parts);
		}


		//その患者のＣＳＶレコード接頭辞的ものを格納
		//「REレコード(14)レセプト特記事項」は4029ではなく2940というように数字の若い順にする
		if($flg_40){
			$RE_parts = explode(',', $REcsv);
			$RE_parts[13] = $RE_parts[13].'40';
			$REcsv = implode(',', $RE_parts);
		}
		/* 処置ID：2658「災１」のコメントを算定したとき
		 * REレコードの14番目の項目(特記事項)に「96」
		 * HOレコードの12番目の項目(減免区分)に「2」を記録
		 */
		if($sai1_flg == true){
			$RE_parts = explode(',', $REcsv);
			$RE_parts[13] .= '96';
			$REcsv = implode(',', $RE_parts);

			$HO_parts = explode(',', $HOcsv);
			$HO_parts[11] .= '2';
			$HOcsv = implode(',', $HO_parts);
		}


		$csv_prefix[$page + 1][$patient_id][$henrei_flg][$saiseikyu] = $IRcsv.$REcsv.$HOcsv.$csv;

	}	//for($page=0; $page<$p_num; $page++){ (患者の数ぶんのFOR文)


		//$page番号 + 1のレセプト共通レコードの昇順でソート
		ksort($csv_prefix);
		foreach($csv_prefix as $recept_no => $v1){
			foreach($v1 as $patient_key => $v2){
				foreach($v2 as $henrei_key => $v3){
					foreach($v3 as $saiseikyu_key => $str_prefix) {

						//プレフィックス出力
						echo $str_prefix;

						//HSレコード出力
						foreach ($csv_hs_array[$patient_key][$henrei_key][$saiseikyu_key] as $hr_csv) {
							echo $hr_csv;
						}

						//処置レコード関連出力
						//患者別の診療識別コードの昇順にレコードを記録。
						$syoche_data = $syoche_sort_array[$patient_key][$henrei_key][$saiseikyu_key];
						ksort($syoche_data);


						foreach ($syoche_data as $sikibetu_key => $tmp_array) {
							//配列の中身（CSV文字列）をソート
							//sort($tmp_array);
							$cp_arr = $tmp_array;
							$temp = array();
							$skip_key = array();
							$str_csv = '';
							foreach ($tmp_array as $str_csv) {
								$cnt = 0;
								$gouzai_flag = 0;
								$day_cnt_arr = null;
								for ($i = 1; $i < 32; $i++) $day_cnt_arr[$i] = '';

								//　コピーした配列とコピー元の配列をつきあわせて、マージさせる
								//　コピー配列で突き合わせているため、単一レコードでも必ず一回はマージが実行される。
								//　回数カラム以外の文字列完全一致検索。
								//　マージ一回は通常の単一出力。$cntの値が1となる。
								//　$cntにはそのレコードの「回数」を代入している。足し合わせていくことでカウントアップ
								foreach ($cp_arr as $key => $val) {
									//つき合わせ時、回数部分のみ除去して確認になる
									if (strcmp(get_group_check_string($str_csv), get_group_check_string($val)) == 0) {

//print "----1=".get_group_check_string($str_csv)."**********=".get_group_check_string($val);


										if (mb_substr($val, 0, 2) == SS_CODE) {
											$tmp_arr = explode(',', $val);
											if ($tmp_arr[77] > 0) {
												$cnt += (int)$tmp_arr[77];
												for ($i = 1; $i < 32; $i++) $day_cnt_arr[$i] += (int)$tmp_arr[77 + $i];
											} else {
												//合算処理の場合。改行コードでレコードごとにわけ、最終行を取得
												// 例）SS\n SS\n TO\nといったレコードを分割。後ろから-2番目になる
												$ss_csv_array = preg_split("/\r\n/", $val);
												$last_recode = $ss_csv_array[count($ss_csv_array) - 2];
												$tmp_arr = explode(',', $last_recode);
												if (mb_substr($last_recode, 0, 2) == SS_CODE) {
													$cnt += (int)$tmp_arr[77];
													//最終レコード以外も同様の回数が記録されているが必要なのは1レコード分のみ
													for ($i = 1; $i < 32; $i++) $day_cnt_arr[$i] += (int)$tmp_arr[77 + $i];
												} else if (mb_substr($last_recode, 0, 2) == SI_CODE) {
													$cnt += (int)$tmp_arr[6];
													for ($i = 1; $i < 32; $i++) $day_cnt_arr[$i] += (int)$tmp_arr[6 + $i];
												} else if (mb_substr($last_recode, 0, 2) == IY_CODE) {
													$cnt += (int)$tmp_arr[6];
													for ($i = 1; $i < 32; $i++) $day_cnt_arr[$i] += (int)$tmp_arr[7 + $i];
												} else if (mb_substr($last_recode, 0, 2) == TO_CODE) {
													$cnt += (int)$tmp_arr[13];
													for ($i = 1; $i < 32; $i++) $day_cnt_arr[$i] += (int)$tmp_arr[13 + $i];
												}
											}
										} else if (mb_substr($val, 0, 2) == SI_CODE) {
											$tmp_arr = explode(',', $val);

											//SIにも合算処理が追加 20101219
											if ($tmp_arr[6] > 0) {
												$cnt += (int)$tmp_arr[6];
												for ($i = 1; $i < 32; $i++) $day_cnt_arr[$i] += (int)$tmp_arr[6 + $i];
											} else {
												$si_csv_array = preg_split("/\r\n/", $val);
												$last_recode = $si_csv_array[count($si_csv_array) - 2];
												$tmp_arr = explode(',', $last_recode);
												$cnt += (int)$tmp_arr[6];
												//最終レコード以外も同様の回数が記録されているが必要なのは1レコード分のみ
												for ($i = 1; $i < 32; $i++) $day_cnt_arr[$i] += (int)$tmp_arr[6 + $i];
											}

										} else if (mb_substr($val, 0, 2) == IY_CODE) {
											//通常は点数回数が必ず入っているが、合剤の場合は両方ない
											//合剤レコードはマージさせないため、合剤フラグを立てて、素通り
											//$tmp_arr = explode(',', $val);
											//if($tmp_arr[6] == ''){
											//	$gouzai_flag = 1;
											//}else{
											//	$cnt += (int)$tmp_arr[6];
											//}
//print "AAA=".$val;
											$tmp_arr = explode(',', $val);
											if ($tmp_arr[6] > 0) {
												$cnt += (int)$tmp_arr[6];
												for ($i = 1; $i < 32; $i++) $day_cnt_arr[$i] += (int)$tmp_arr[7 + $i];
											} else {
												//合剤処理の場合。改行コードでレコードごとにわけ、最終行を取得
												$iy_csv_array = preg_split("/\r\n/", $val);
												$last_recode = $iy_csv_array[count($iy_csv_array) - 2];
												$tmp_arr = explode(',', $last_recode);
												$cnt += (int)$tmp_arr[6];
												//最終レコード以外も同様の回数が記録されているが必要なのは1レコード分のみ
												for ($i = 1; $i < 32; $i++) $day_cnt_arr[$i] += (int)$tmp_arr[7 + $i];
											}
										} else if (mb_substr($val, 0, 2) == TO_CODE) {
											$tmp_arr = explode(',', $val);
											$cnt += (int)$tmp_arr[13];
											for ($i = 1; $i < 32; $i++) $day_cnt_arr[$i] += (int)$tmp_arr[13 + $i];

										} else if (mb_substr($val, 0, 2) == CO_CODE) {
											$cnt++;
										}
										$skip_key[] = $key;
									}
								}

								//マージしたレコードを保存
								if ($cnt > 0 || $gouzai_flag == 1) {
									$echo_str = '';
									if (mb_substr($str_csv, 0, 2) == SS_CODE) {
										$tmp_arr = explode(',', $str_csv);
										if ($tmp_arr[77] != '') {
											$tmp_arr[77] = $cnt;
											for ($i = 1; $i < 32; $i++) $tmp_arr[77 + $i] = ((int)$day_cnt_arr[$i] > 0) ? (int)$day_cnt_arr[$i] : '';
											$tmp_arr[108] = $tmp_arr[108] . "\r\n";
											$echo_str = implode(',', $tmp_arr);
										} else {
											//合算処理の場合。改行コードでレコードごとにわけ、最終行を取得
											$ss_csv_array = preg_split("/\r\n/", $str_csv);
											for ($h = 0; $h < (count($ss_csv_array) - 1); $h++) {
												$recode = $ss_csv_array[$h];
												$tmp_arr = explode(',', $recode);
												if (mb_substr($recode, 0, 2) == SS_CODE) {
													$tmp_arr[77] = $cnt;
													for ($i = 1; $i < 32; $i++) $tmp_arr[77 + $i] = ((int)$day_cnt_arr[$i] > 0) ? (int)$day_cnt_arr[$i] : '';
												} else if (mb_substr($recode, 0, 2) == SI_CODE) {
													$tmp_arr[6] = $cnt;
													for ($i = 1; $i < 32; $i++) $tmp_arr[6 + $i] = ((int)$day_cnt_arr[$i] > 0) ? (int)$day_cnt_arr[$i] : '';
												} else if (mb_substr($recode, 0, 2) == IY_CODE) {
													$tmp_arr[6] = $cnt;
													for ($i = 1; $i < 32; $i++) $tmp_arr[7 + $i] = ((int)$day_cnt_arr[$i] > 0) ? (int)$day_cnt_arr[$i] : '';
												} else if (mb_substr($recode, 0, 2) == TO_CODE) {
													$tmp_arr[13] = $cnt;
													for ($i = 1; $i < 32; $i++) $tmp_arr[13 + $i] = ((int)$day_cnt_arr[$i] > 0) ? (int)$day_cnt_arr[$i] : '';
												}
												$ss_csv_array[$h] = implode(',', $tmp_arr);
											}

//										$last_recode = $ss_csv_array[count($ss_csv_array)-2];
//										$tmp_arr = explode(',', $last_recode);
//										if(mb_substr($last_recode,0,2) == SS_CODE){
//											$tmp_arr[77] = $cnt;
//										}else if(mb_substr($last_recode,0,2) == SI_CODE){
//											$tmp_arr[6] = $cnt;
//										}else if(mb_substr($last_recode,0,2) == IY_CODE){
//											$tmp_arr[6] = $cnt;
//										}else if(mb_substr($last_recode,0,2) == TO_CODE){
//											$tmp_arr[13] = $cnt;
//										}
//										$ss_csv_array[count($ss_csv_array)-2] = implode(',', $tmp_arr);
											$echo_str = implode(CSV_BREAK, $ss_csv_array);
										}
									} else if (mb_substr($str_csv, 0, 2) == SI_CODE) {
										$tmp_arr = explode(',', $str_csv);
										if ($tmp_arr[6] != '') {
											$tmp_arr[6] = $cnt;
											for ($i = 1; $i < 32; $i++) $tmp_arr[6 + $i] = ((int)$day_cnt_arr[$i] > 0) ? (int)$day_cnt_arr[$i] : '';
											$tmp_arr[37] = $tmp_arr[37] . "\r\n";
											$echo_str = implode(',', $tmp_arr);
										} else {
											//合算処理の場合。改行コードでレコードごとにわけ、最終行を取得
											$si_csv_array = preg_split("/\r\n/", $str_csv);
											//$last_recode = $si_csv_array[count($si_csv_array)-2];
											//$tmp_arr = explode(',', $last_recode);
											//$tmp_arr[6] = $cnt;
											//$si_csv_array[count($si_csv_array)-2] = implode(',', $tmp_arr);

											for ($h = 0; $h < (count($si_csv_array) - 1); $h++) {
												$tmp_arr = explode(',', $si_csv_array[$h]);
												$tmp_arr[6] = $cnt;
												for ($i = 1; $i < 32; $i++) $tmp_arr[6 + $i] = ((int)$day_cnt_arr[$i] > 0) ? (int)$day_cnt_arr[$i] : '';
												$si_csv_array[$h] = implode(',', $tmp_arr);
											}
											$echo_str = implode(CSV_BREAK, $si_csv_array);
										}

									} else if (mb_substr($str_csv, 0, 2) == IY_CODE) {

//print "###########".$str_csv."";
										$tmp_arr = explode(',', $str_csv);
										if ($tmp_arr[6] != '') {
											$tmp_arr[6] = $cnt;
											for ($i = 1; $i < 32; $i++) $tmp_arr[7 + $i] = ((int)$day_cnt_arr[$i] > 0) ? (int)$day_cnt_arr[$i] : '';
											$tmp_arr[38] = $tmp_arr[38] . "\r\n";
											$echo_str = implode(',', $tmp_arr);
										} else {
											//合剤処理の場合。改行コードでレコードごとにわけ、最終行を取得
											$iy_csv_array = preg_split("/\r\n/", $str_csv);
//var_dump($iy_csv_array);
											for ($h = 0; $h < (count($iy_csv_array) - 1); $h++) {
												$tmp_arr = explode(',', $iy_csv_array[$h]);
												$tmp_arr[6] = $cnt;
												for ($i = 1; $i < 32; $i++) $tmp_arr[7 + $i] = ((int)$day_cnt_arr[$i] > 0) ? (int)$day_cnt_arr[$i] : '';
												$iy_csv_array[$h] = implode(',', $tmp_arr);
											}
											$echo_str = implode(CSV_BREAK, $iy_csv_array);
										}

									} else if (mb_substr($str_csv, 0, 2) == TO_CODE) {
										$tmp_arr = explode(',', $str_csv);
										$tmp_arr[13] = $cnt;
										for ($i = 1; $i < 32; $i++) $tmp_arr[13 + $i] = ((int)$day_cnt_arr[$i] > 0) ? (int)$day_cnt_arr[$i] : '';
										$tmp_arr[44] = $tmp_arr[44] . "\r\n";
										$echo_str = implode(',', $tmp_arr);
									} else if (mb_substr($str_csv, 0, 2) == CO_CODE) {
										$echo_str = $str_csv;
									}
									$temp[] = $echo_str;
									//マージ済みのレコードを削除
									foreach ($skip_key as $del_key) {
										unset($cp_arr[$del_key]);
									}
								}
							}
							foreach ($temp as $marge_csv) {
								echo $marge_csv;
							}
						}

						echo $csv_rireki[$recept_no][$patient_key];
					}
				}
			}
		}
	}
	//患者数の問い合わせのif文

	//************************************************************************************
	//診療報酬請求書レコード
	//************************************************************************************
	$go_arr = array();
	$go_arr[] = GO_CODE;
	$go_arr[] = $rece_total;		//総件数
	$go_arr[] = $sum_total;			//総合計点数
	$go_arr[] = 99;					//マルチボリューム識別情報
	echo create_csv($go_arr);


	//************************************************************************************
	//CSV出力
	//************************************************************************************

	ob_end_flush();
}
//function output終了



	//EUC-JP to UTF8
	function to_utf8($s){
		return mb_convert_encoding($s, SYS_ENCODE, DB_ENCODE);
	}

//年齢計算
function calc_age($birth, $startdate){
    list($by, $bm, $bd) = explode('/', $birth);
    list($ty, $tm, $td) = explode('/', $startdate);
    $age = $ty - $by;
    if($tm * 100 + $td < $bm * 100 + $bd) $age--;
    return $age;
}

//年齢計算(システム日付を基準に）
function calc_age_now($birth){
    list($by, $bm, $bd) = explode('/', $birth);
    list($ty, $tm, $td) = explode('/', date('Y/m/d'));
    $age = $ty - $by;
    if($tm * 100 + $td < $bm * 100 + $bd) $age--;
    return $age;
}

	//年月整形
	function change_era($start_date){
		$pre = '';
		$era = '';
		$split_date = explode('/', $start_date);
		$year = $split_date[0];
		$m = $split_date[1];

		if($year > 1988){
			$era = $year - 1988;
			$pre = ERA_H;
		}else if($year > 1925 && $year <= 1988){
			$era = $year - 1925;
			$pre = ERA_S;
		}else if($year > 1911 && $year <= 1925){
			$era = $year - 1911;
			$pre = ERA_T;
		}else{
			$era = $year - 1867;
			$pre = ERA_M;
		}
		$era = sprintf("%02d", $era);
		$m = sprintf("%02d", $m);
		return $pre.$era.$m;
	}

	//年月日整形
	function change_era_ymd($date){
		$pre = '';
		$era = '';
		if($date != ''){
			$split_date = explode('/', $date);
			$y = $split_date[0];
			$m = $split_date[1];
			$d = $split_date[2];

			//年月日を文字列として結合
			$ymd = sprintf("%02d%02d%02d", $y, $m, $d);
			if ($ymd <= "19120729") {
				$pre = ERA_M;
				$era = $y - 1867;
			} elseif ($ymd >= "19120730" && $ymd <= "19261224") {
				$pre = ERA_T;
				$era = $y - 1911;
			} elseif ($ymd >= "19261225" && $ymd <= "19890107") {
				$pre = ERA_S;
				$era = $y - 1925;
			} elseif ($ymd >= "19890108") {
				$pre = ERA_H;
				$era = $y - 1988;
			}

			$era = sprintf("%02d", $era);
			$m = sprintf("%02d", $m);
			$d = sprintf("%02d", $d);
			return $pre.$era.$m.$d;
		}
		return '';
	}

	//和暦を西暦に変換
	function eraToAD($year){
		if($year != ''){
			return intval($year) + 1988;
		}
		return '';
	}


	//施設基準届出コード整形
	function create_facility_criteria_code($arr){

		$code = '';
		if($arr[0] == 1) $code .= '01';

// 2018-04-20 OHTA DEL

		//if($arr[1] == 1) $code .= '02';
		//if($arr[2] == 1) $code .= '03';
		//if($arr[3] == 1) $code .= '04';
		//if($arr[5] == 1) $code .= '06';
		//if($arr[6] == 1) $code .= '07';
		//if($arr[7] == 1) $code .= '08';
		//if($arr[8] == 1) $code .= '09';
		//if($arr[9] == 1) $code .= '10';
		//if($arr[11] == 1) $code .= '12';
		//if($arr[12] == 1) $code .= '13';
		//if($arr[13] == 1) $code .= '14';
		//if($arr[14] == 1) $code .= '15';
		//if($arr[15] == 1) $code .= '16';

// 2018-04-20 OHTA END

		return $code;
	}

	//任意の月と比較
	function compare_month($date, $compare){
		$timestamp = strtotime($date);
		$month_timestamp = strtotime(date("Y-m", strtotime($compare))."-01");

		if($timestamp < $month_timestamp){
			return true;
		}else{
			return false;
		}
	}

	//先月の日付と比較
	function compare_date_last_month($date){
		$timestamp = strtotime($date);
		$last_month_timestamp = strtotime(date("Y-m", strtotime("last month"))."-01");

		if($timestamp <= $last_month_timestamp){
			return true;
		}else{
			return false;
		}
	}

	//歯科診療行為CSV配列を作成
    //合算歯科診療行為レコード2～4
	function create_ss($syochiMasterData, $s_db, $k, $mst, $last_flg, $num, $kasan_mst_array, $extra_code, &$tensu, $sisiki_list, &$mapping_checked_list){

		//加算コード用の空配列を作成
		$kasan_array = array();
		$kasan_num_array = array();

		for($l=0; $l<35; $l++){
			$kasan_array[$l] = null;
			$kasan_num_array[$l] = '';
		}

		$ss_code		 = $syochiMasterData['SS_CODE'.$num];					//診療行為コード
		$ss_kizami		 = $syochiMasterData['SS_KIZAMI'.$num];					//診療行為きざみ
		$ss_kizami_suryo = $syochiMasterData['SS_KIZAMI_SURYO'.$num];			//診療行為きざみ数量
		$futan_type = $mst['futan_type'];
		//$tensu = $mst['tensu'];
		$honsu = $mst['honsu'];

		$honsu = $s_db->get("HONSU", $k);

		$ss_kasan_code   = $syochiMasterData['SS_KASAN_CODE'.$num];			//加算コード(カンマ区切り想定)
		$ss_kasan_kizami = $syochiMasterData['SS_KASAN_KIZAMI'.$num];			//加算きざみ(カンマ区切り想定)
        $ss_kasan_suryo  = $syochiMasterData['SS_KASAN_SURYO'.$num];			//加算数量(カンマ区切り想定)
        $ss_kasan_sort   = $syochiMasterData['SS_KASAN_SORT'.$num];			//加算記録順

		$ss_kasan_code_arr = explode(',', $ss_kasan_code);
		$ss_kasan_kizami_arr = explode(',', $ss_kasan_kizami);
		$ss_kasan_suryo_arr = explode(',', $ss_kasan_suryo);

		//加算コードを弾込め
		for($l=0; $l<35; $l++){
            $kasan_array[$l] = (object) array('code' => $ss_kasan_code_arr[$l], 'sort' => $ss_kasan_sort);  //KasanData扱いにしたいのですが・・

			if($ss_kasan_kizami_arr[$l] == 1){
				//もし加算数量が設定されていなければ算定数を代入
                if($ss_kasan_suryo_arr[$l] == '') {
                    $kasan_num_array[$l] = $honsu;
                }else{
                    $kasan_num_array[$l] = $ss_kasan_suryo_arr[$l];
                }
			}else{
				$kasan_num_array[$l] = '';
			}
		}

		//もしその診療行為コードに対して加算用診療行為が用意されていたら、加算しておく。
		if(is_array($kasan_mst_array[$ss_code])){
			foreach($kasan_mst_array[$ss_code] as $ss_obj){

				// 20101230 条件追加　同一部位であれば加算すること
				if($sisiki_list[$k] == $ss_obj->bui_code && !in_array($ss_obj->check_digit, $mapping_checked_list)){

                    $map_kasan_code = $ss_obj->code;	//加算コード
                    $map_kasan_num = $ss_obj->suryo;	//加算数量
                    $map_kasan_kizami = $ss_obj->kizami;	//加算きざみ
                    $map_kasan_sort = $ss_obj->sort;    //加算表示順

					//加算コードの末尾に追加
					$cnt = -1;
					foreach($kasan_array as $key => $val){
						if($val != '') continue;
						$cnt = $key;
						break;
					}

					if($cnt != -1){
						foreach($map_kasan_code as $key => $val){
                            $kasan_array[$cnt] = (object) array('code' => $val, 'sort' => $map_kasan_sort);  //KasanData扱いにしたいのですが・・

							// 20101230 加算コードマッピング時の点数更新は下記公式にて実行のこと
							// 加算数量＝加算処置の算定数÷対象処置の算定数
							// 点数＝対象処置点数＋加算処置点数×加算数量

							$kasan_honsu = $map_kasan_num[$key];
							$kasan_suryo = intval($kasan_honsu / $honsu);
							if($map_kasan_kizami[$key] == 0){
								$kasan_suryo = 1;
								$kasan_num_array[$cnt] = '';
							}else{
								$kasan_num_array[$cnt] = $kasan_suryo;
							}
							$tensu += $ss_obj->tensu * $kasan_suryo;

							//使用済みマッピングを登録
							$mapping_checked_list[] = $ss_obj->check_digit;

							if($cnt > 34) break;
							else $cnt++;
						}
					}
				}
			}
		}

		foreach($extra_code as $code){
			//加算コードの末尾に追加
			$cnt = -1;
			foreach($kasan_array as $key => $val){
				if((is_object($val)) && ($val->code != "")){
					continue;
				}
				$cnt = $key;
				break;
			}

			if($cnt != -1){
                $kasan_array[$cnt] = (object) array('code' => $code, 'sort' => 0);  //KasanData扱いにしたいのですが・・
            }
		}


		//加算コード並び替え処理
		$add_code_data = ajust_kasan_code_with_sort($kasan_array, $kasan_num_array);


		//SSレコード配列作成
		$ss_arr = array();
		$ss_arr[] = SS_CODE;
		$ss_arr[] = '';					//診療識別(固定で省略の予定)
		$ss_arr[] = $futan_type;		//負担区分
		$ss_arr[] = $ss_code;			//診療行為コード
		($ss_kizami == 1) ? $ss_arr[] = $ss_kizami_suryo : $ss_arr[] = '';		//診療行為数量データ1
		$ss_arr[] = '';					//診療行為数量データ2

		//加算コードの配列を追加
		for($l=0; $l<35; $l++){
			$ss_arr[] = $add_code_data[$l]['kasan_code'];
			$ss_arr[] = $add_code_data[$l]['kasan_num'];
		}

		//最終レコードの場合、点数と回数を記録
		if($last_flg == 1){
			$ss_arr[] = $tensu;			//点数
			$ss_arr[] = $honsu;			//回数
		}else{
			$ss_arr[] = '';				//点数
			$ss_arr[] = '';				//回数
		}

		//1-31日の情報
		//for($l=0; $l<31; $l++) $ss_arr[] = '';
		$jyushin_day = (int)substr($mst['jyushin_date'], -2);
		for($l=0; $l<31; $l++) ($jyushin_day == $l+1 && $mst['miraiin_flg'] == 0) ? $ss_arr[] = $honsu : $ss_arr[] = '';

		return $ss_arr;
	}

	//歯科との合算用の医科診療CSV配列を作成
	function create_si($syochiMasterData, $s_db, $k, $mst, $last_flg, $first_flg=1){
		$futan_type = $mst['futan_type'];
		$tensu = $mst['tensu'];
		$sikibetu_code = $mst['sikibetu_code'];
		$si_code = $syochiMasterData['SI_CODE'];
		$si_kizami = $syochiMasterData['SI_KIZAMI'];
		$si_suryo  = $syochiMasterData['SI_SURYO'];
		$honsu = $s_db->get("HONSU", $k);			//本数

		$si_arr = array();
		$si_arr[] = SI_CODE;
		($first_flg == 1) ? $si_arr[] = $sikibetu_code : $si_arr[] = '';	//診療識別
		$si_arr[] = $futan_type;		//負担区分
		$si_arr[] = $si_code;
		($si_kizami == 1) ? $si_arr[] = $si_suryo : $si_arr[] = '';	//数量データ
		if($last_flg == 1){
			$si_arr[] = $tensu;			//点数
			$si_arr[] = $honsu;			//回数
		}else{
			$si_arr[] = '';				//点数
			$si_arr[] = '';				//回数
		}

		//1-31日の情報
		//for($l=0; $l<31; $l++) $si_arr[] = '';
		$jyushin_day = (int)substr($mst['jyushin_date'], -2);
		for($l=0; $l<31; $l++) ($jyushin_day == $l+1 && $mst['miraiin_flg'] == 0) ? $si_arr[] = $honsu : $si_arr[] = '';

		return $si_arr;
	}

	//医科診療CSV配列を作成（単体での合算処理の可能性あり）
	function create_gassan_si($syochiMasterData, $s_db, $k, $mst){
		$si_csv = '';

		$futan_type = $mst['futan_type'];
		$tensu = $mst['tensu'];
		$sikibetu_code = $mst['sikibetu_code'];

		$honsu = $s_db->get("HONSU", $k);			//本数

		//SI_CODE、SI_CODE2-20を想定
		$si_code = $syochiMasterData['SI_CODE'];
		$si_kizami = $syochiMasterData['SI_KIZAMI'];
		$si_suryo  = $syochiMasterData['SI_SURYO'];

		$si_arr = array();
		$si_arr[] = SI_CODE;
		$si_arr[] = $sikibetu_code;		//診療識別
		$si_arr[] = $futan_type;		//負担区分
		$si_arr[] = $si_code;
		($si_kizami == 1) ? $si_arr[] = $si_suryo : $si_arr[] = '';	//数量データ
		if($syochiMasterData['SI_CODE2'] == ''){
			$si_arr[] = $tensu;			//点数
			$si_arr[] = $honsu;			//回数
		}else{
			$si_arr[] = '';				//点数
			$si_arr[] = '';				//回数
		}

		//1-31日の情報
		$jyushin_day = (int)substr($mst['jyushin_date'], -2);
		for($l=0; $l<31; $l++) ($jyushin_day == $l+1 && $mst['miraiin_flg'] == 0) ? $si_arr[] = $honsu : $si_arr[] = '';

		$si_csv .= create_csv($si_arr);

		for($i=2; $i<=20; $i++){
			$si_code = $syochiMasterData['SI_CODE'.$i];
			$si_kizami = $syochiMasterData['SI_KIZAMI'.$i];
			$si_suryo = $syochiMasterData['SI_SURYO'.$i];
			if($si_code == '') break;

			$si_arr = array();
			$si_arr[] = SI_CODE;
			$si_arr[] = '';					//診療識別
			$si_arr[] = $futan_type;		//負担区分
			$si_arr[] = $si_code;
			($si_kizami == 1) ? $si_arr[] = $si_suryo : $si_arr[] = '';	//数量データ
			$si_index = $i+1;
			if($syochiMasterData['SI_CODE'.$si_index] == ''){
				$si_arr[] = $tensu;			//点数
				$si_arr[] = $honsu;			//回数
			}else{
				$si_arr[] = '';				//点数
				$si_arr[] = '';				//回数
			}

			//1-31日の情報
			//for($l=0; $l<31; $l++) $si_arr[] = '';
			$jyushin_day = (int)substr($mst['jyushin_date'], -2);
			for($l=0; $l<31; $l++) ($jyushin_day == $l+1 && $mst['miraiin_flg'] == 0) ? $si_arr[] = $honsu : $si_arr[] = '';

			$si_csv .= create_csv($si_arr);
		}

		return $si_csv;
	}


	//医薬品CSV配列を作成
	function create_iy($syochiMasterData, $s_db, $k, $mst, $last_flg, $first_flg=1, $gassan_flg=0){
		$futan_type = $mst['futan_type'];
		$tensu = $mst['tensu'];
		$sikibetu_code = $mst['sikibetu_code'];
		$iy_code = $mst['iy_code'];
		$iy_shiyoryo_kisai = $mst['iy_shiyoryo_kisai'];
		$iy_shiyoryo = $mst['iy_shiyoryo'];

//		$iy_code1  = $dbo->get("IY_CODE1", 0);
		$yakuzai_flg  = $syochiMasterData['YAKUZAI_FLG'];
		$honsu = $s_db->get("HONSU", $k);			//本数
		$youhou = $s_db->get("YOUHOU", $k);			//用法
		$ikkairyou = $s_db->get("IKKAIRYO", $k);	//一回量
//		if($ikkairyou == '0') $ikkairyou = '';

		/*
		 * #2856 コメント12
		 * 用法で「痛時」を選択した場合は、登録されている医薬品区分値は無視して、医薬品区分は無条件に「2」と記録する(「2」なので、複数でも合剤計算はされません)。
		 */
		if($youhou == 5){
			$yakuzai_flg = 2;
		}

		$youhou_ryou = 1;
		switch($youhou){
			case 1:
				$youhou_ryou = 2;
				break;
			case 2:
				$youhou_ryou = 3;
				break;
			case 3:
				$youhou_ryou = 3;
				break;
			case 4:
				$youhou_ryou = 4;
				break;
			case 5:
				$youhou_ryou = 1;
				break;
			case 6:
				$youhou_ryou = 1;
				break;
			case 7:
				$youhou_ryou = 1;
				break;
			default:
				break;
		}

		/**
		* IYの金額種別が3のものなどは、使用量を省略する必要があります。（手引きP.46）
		* IY1、IY2に対して、「使用量の記載」と「使用量」の欄を設けていただけますでしょうか？
		* 「使用量の記載」=空欄　の場合、使用量をIYの5桁めに記載します。
		* 「使用量の記載」=1　の場合、CSVに使用量は省略します。
		*
		* 「使用量」=空欄　の場合、カルテ入力画面上で入力された1回量を記載します。
		* 「使用量」=数字　の場合、その「数字」をIY5桁目の使用量に記載します。
		*/
		$shiyoryo = '';
		if($iy_shiyoryo_kisai != '1'){
			if($iy_shiyoryo == ''){
				$shiyoryo = $ikkairyou * $youhou_ryou;				//使用量
			}else{
				$shiyoryo = $iy_shiyoryo;							//使用量
			}
		}

		$iy_arr = array();
		$iy_arr[] = IY_CODE;
		($first_flg == 1) ? $iy_arr[] = $sikibetu_code : $iy_arr[] = '';	//診療識別
		$iy_arr[] = $futan_type;							//負担区分
		$iy_arr[] = $iy_code;
		$iy_arr[] = $shiyoryo;			//使用量

		if($gassan_flg == 1){
			$iy_arr[] = $tensu;			//点数
			$iy_arr[] = 1;				//合算処理用とし、回数を1回とする
		}else if($last_flg == 1){
			$iy_arr[] = $tensu;			//点数
			$iy_arr[] = $honsu;			//回数(本数？)
		}else{
			$iy_arr[] = '';				//点数
			$iy_arr[] = '';
		}
		//$iy_arr[] = $yakuzai_flg;		//医薬品区分
		($first_flg == 1) ? $iy_arr[] = $yakuzai_flg : $iy_arr[] = '';	//医薬品区分

		//1-31日の情報
//var_dump($mst['jyushin_date']);
		$jyushin_day = (int)substr($mst['jyushin_date'], -2);
		for($l=0; $l<31; $l++) ($jyushin_day == $l+1 && $mst['miraiin_flg'] == 0) ? $iy_arr[] = $honsu : $iy_arr[] = '';

		return $iy_arr;
	}

	//特定器材CSV配列を作成
	function create_to($syochiMasterData, $s_db, $k, $mst, $last_flg, $first_flg=1){
		$futan_type = $mst['futan_type'];
		$tensu = $mst['tensu'];
		$sikibetu_code = $mst['sikibetu_code'];
		$to_code = $syochiMasterData['TO_CODE'];
		$to_siyoryo = $syochiMasterData['TO_SIYORYO'];
		$to_tanka = $syochiMasterData['TO_TANKA'];
		$to_tani_code = $syochiMasterData['TO_TANI_CODE'];
		$to_kasan_code = $syochiMasterData['TO_KASAN_CODE'];
		$to_kasan_suryo = $syochiMasterData['TO_KASAN_SURYO'];
		$to_meisyo = $syochiMasterData['TO_MEISYO'];
		$honsu = $s_db->get("HONSU", $k);			//本数
		//if($to_siyoryo == 0) $to_siyoryo = $honsu;		//もし使用量が空欄だったら算定数を出力
		if($to_kasan_suryo == '0') $to_kasan_suryo = '';

		$to_arr = array();
		$to_arr[] = TO_CODE;
		($first_flg == 1) ? $to_arr[] = $sikibetu_code : $to_arr[] = '';	//診療識別
		$to_arr[] = $futan_type;		//負担区分
		$to_arr[] = $to_code;
		$to_arr[] = $to_siyoryo;		//使用量
		$to_arr[] = $to_tani_code;		//単位コード
		$to_arr[] = $to_tanka;			//単価
		$to_arr[] = $to_kasan_code;		//特定器材加算等コード1
		$to_arr[] = $to_kasan_suryo;	//特定器材加算等数量データ1
		$to_arr[] = '';					//特定器材加算等コード2
		$to_arr[] = '';					//特定器材加算等数量データ2
		$to_arr[] = $to_meisyo;			//名称

		if($last_flg == 1){
			$to_arr[] = $tensu;			//点数
			//$to_arr[] = 1;				//回数　→　使用量に算定数を使用するため1で固定
			$to_arr[] = $honsu;				//回数　↑　案の定そんなはずはないわけで…20101207に算定数入れるよう修正
		}else{
			$to_arr[] = '';				//点数
			$to_arr[] = '';
		}

		//1-31日の情報
		$jyushin_day = (int)substr($mst['jyushin_date'], -2);
		for($l=0; $l<31; $l++) ($jyushin_day == $l+1 && $mst['miraiin_flg'] == 0) ? $to_arr[] = $honsu : $to_arr[] = '';

		return $to_arr;
	}


	//************************************************************************************
	//歯式作成
	//************************************************************************************
	function create_sisiki_code($bui_ue, $bui_shita){
		global $condition_mapping_code;
		global $bui_mapping_code;
		global $tooth_code_ue;
		global $tooth_code_shita;
		global $over_tooth_code_ue;
		global $over_tooth_code_shita;
		global $p_check_zengaku;

		$sisiki_code = '';
/*
		//ここに全顎チェックをいれて、全顎の場合は歯式1000 00
		$zengaku_flg = true;
		for($i=0; $i<26; $i++){
			if($p_check_zengaku[$i] !== mb_substr($bui_ue, $i, 1)) $zengaku_flg = false;
			if($p_check_zengaku[$i] !== mb_substr($bui_shita, $i, 1)) $zengaku_flg = false;
		}
		if($zengaku_flg) return ZENGAKU;
*/
		//上の歯
		for($i=0; $i<26; $i++){
			//歯のステータスを一文字づつ取り出す
			$ha = '';
			$ha = mb_substr($bui_ue, $i, 1);
			if($ha != '0'){

				if($ha == '9')continue;

				//もし遠心根支台歯か近心根支台歯の場合、欠損歯の記録を先にする可能性がある
				//Cは101,104だと先に欠損歯を記録
				//Dは102,103だと先に欠損歯を記録
				if($ha == 'C' || $ha == 'D'){
					$check_tooth_code = mb_substr($tooth_code_ue[$i], 0, 3);
					if($ha == 'D' && ($check_tooth_code == '102' || $check_tooth_code == '103')){
						$sisiki_code .= $tooth_code_ue[$i].'20';
					}else if($ha == 'C' && ($check_tooth_code == '101' || $check_tooth_code == '104')){
						$sisiki_code .= $tooth_code_ue[$i].'20';
					}
				}

				//「隙」の場合
				if($ha == '4'){
					$check_tooth_code = mb_substr($tooth_code_ue[$i], 0, 3);
					//歯種コード「101・」「104・」と「105・」「108・」では「隙部分が後」、
					//隙の対象となっている歯種コード4桁+状態コード1or3or5+部分コード0の後ろに続けて、
					//隙の対象となっている歯種コード4桁+状態コード8+部分コード0
					if($check_tooth_code == '101' || $check_tooth_code == '104' || $check_tooth_code == '105' || $check_tooth_code == '108'){
						$sisiki_code .= $tooth_code_ue[$i].'30';
					}
				}

				//過剰歯の場合はマスター配列を変更
				if($ha == 6 || $ha == 7){
					$sisiki_code .= $over_tooth_code_ue[$i];
				}else{
					$sisiki_code .= $tooth_code_ue[$i];
				}

				//状態コード
				$sisiki_code .= $condition_mapping_code[$ha];

				//部位コード
				if($ha == 'A' || $ha == 'C' || $ha == 'D'){
					$sisiki_code .= $bui_mapping_code[$ha];
					if($ha == 'A') $sisiki_code .= $tooth_code_ue[$i].$condition_mapping_code[$ha].$bui_mapping_code[$ha];
				}else{
					$sisiki_code .= '0';
				}

				//もし遠心根支台歯か近心根支台歯の場合、欠損歯の記録を後にする可能性がある
				//Cは102,103だと先に欠損歯を記録
				//Dは101,104だと先に欠損歯を記録
				if($ha == 'C' || $ha == 'D'){
					$check_tooth_code = mb_substr($tooth_code_ue[$i], 0, 3);
					if($ha == 'D' && ($check_tooth_code == '101' || $check_tooth_code == '104')){
						$sisiki_code .= $tooth_code_ue[$i].'20';
					}else if($ha == 'C' && ($check_tooth_code == '102' || $check_tooth_code == '103')){
						$sisiki_code .= $tooth_code_ue[$i].'20';
					}
				}

				//「隙」の場合
				if($ha == '4'){
					$check_tooth_code = mb_substr($tooth_code_ue[$i], 0, 3);
					if($check_tooth_code == '102' || $check_tooth_code == '103' || $check_tooth_code == '106' || $check_tooth_code == '107'){
						$sisiki_code .= $tooth_code_ue[$i].'30';
					}
				}
			}
		}

		//下の歯
		for($i=0; $i<26; $i++){
			//歯のステータスを一文字づつ取り出す
			$ha = '';
			$ha = mb_substr($bui_shita, $i, 1);
			if($ha != '0'){

				if($ha == '9')continue;

				//もし遠心根支台歯か近心根支台歯の場合、欠損歯の記録を先にする可能性がある
				//Dは102,103だと先に欠損歯を記録
				//Cは101,104だと先に欠損歯を記録
				if($ha == 'C' || $ha == 'D'){
					$check_tooth_code = mb_substr($tooth_code_shita[$i], 0, 3);
					if($ha == 'D' && ($check_tooth_code == '102' || $check_tooth_code == '103')){
						$sisiki_code .= $tooth_code_shita[$i].'20';
					}else if($ha == 'C' && ($check_tooth_code == '101' || $check_tooth_code == '104')){
						$sisiki_code .= $tooth_code_shita[$i].'20';
					}
				}

				//「隙」の場合
				if($ha == '4'){
					$check_tooth_code = mb_substr($tooth_code_shita[$i], 0, 3);
					if($check_tooth_code == '101' || $check_tooth_code == '104' || $check_tooth_code == '105' || $check_tooth_code == '108'){
						$sisiki_code .= $tooth_code_shita[$i].'30';
					}
				}

				//過剰歯の場合はマスター配列を変更
				if($ha == 6 || $ha == 7){
					$sisiki_code .= $over_tooth_code_shita[$i];
				}else{
					$sisiki_code .= $tooth_code_shita[$i];
				}

				//状態コード
				$sisiki_code .= $condition_mapping_code[$ha];

				//部位コード
				if($ha == 'A' || $ha == 'C' || $ha == 'D'){
					$sisiki_code .= $bui_mapping_code[$ha];
					if($ha == 'A') $sisiki_code .= $tooth_code_shita[$i].$condition_mapping_code[$ha].$bui_mapping_code[$ha];
				}else{
					$sisiki_code .= '0';
				}

				//もし遠心根支台歯か近心根支台歯の場合、欠損歯の記録を後にする可能性がある
				//Dは101,104だと先に欠損歯を記録
				//Cは102,103だと先に欠損歯を記録
				if($ha == 'C' || $ha == 'D'){
					$check_tooth_code = mb_substr($tooth_code_shita[$i], 0, 3);
					if($ha == 'D' && ($check_tooth_code == '101' || $check_tooth_code == '104')){
						$sisiki_code .= $tooth_code_shita[$i].'20';
					}else if($ha == 'C' && ($check_tooth_code == '102' || $check_tooth_code == '103')){
						$sisiki_code .= $tooth_code_shita[$i].'20';
					}
				}

				//「隙」の場合
				if($ha == '4'){
					$check_tooth_code = mb_substr($tooth_code_shita[$i], 0, 3);
					if($check_tooth_code == '102' || $check_tooth_code == '103' || $check_tooth_code == '106' || $check_tooth_code == '107'){
						$sisiki_code .= $tooth_code_shita[$i].'30';
					}
				}
			}
		}
		return $sisiki_code;
	}

	//処置のグループ化にあたり、突き合わせチェックの下準備
	function get_group_check_string($str_csv){
		$tmp_arr = explode(',', $str_csv);
		if(mb_substr($str_csv,0,2) == SS_CODE){
			if($tmp_arr[77] > 0){
				$tmp_arr[77] = 0;
				for($i=1; $i<32; $i++) $tmp_arr[77+$i] = 0;
			}else{
				//合算処理の場合。改行コードでレコードごとにわけ、最終行を取得
				// 例）SS\n SS\n TO\nといったレコードを分割。後ろから-2番目になる
				$ss_csv_array = preg_split("/\r\n/", $str_csv);
//				$last_recode = $ss_csv_array[count($ss_csv_array)-2];
//				$tmp_arr = explode(',', $last_recode);
//				if(mb_substr($last_recode,0,2) == SS_CODE){
//					$tmp_arr[77] = 0;
//				}else if(mb_substr($last_recode,0,2) == SI_CODE){
//					$tmp_arr[6] = 0;
//				}else if(mb_substr($last_recode,0,2) == IY_CODE){
//					$tmp_arr[6] = 0;
//				}else if(mb_substr($last_recode,0,2) == TO_CODE){
//					$tmp_arr[13] = 0;
//				}
//				$ss_csv_array[count($ss_csv_array)-2] = implode(',', $tmp_arr);

				for($h=0; $h<(count($ss_csv_array)-1); $h++){
					$recode = $ss_csv_array[$h];
					$tmp_arr = explode(',', $recode);
					if(mb_substr($recode,0,2) == SS_CODE){
						$tmp_arr[77] = 0;
						for($i=1; $i<32; $i++) $tmp_arr[77+$i] = 0;
					}else if(mb_substr($recode,0,2) == SI_CODE){
						$tmp_arr[6] = 0;
						for($i=1; $i<32; $i++) $tmp_arr[6+$i] = 0;
					}else if(mb_substr($recode,0,2) == IY_CODE){
						$tmp_arr[6] = 0;
						for($i=1; $i<32; $i++) $tmp_arr[7+$i] = 0;
					}else if(mb_substr($recode,0,2) == TO_CODE){
						$tmp_arr[13] = 0;
						for($i=1; $i<32; $i++) $tmp_arr[13+$i] = 0;
					}
					$ss_csv_array[$h] = implode(',', $tmp_arr);
				}

				return implode(CSV_BREAK, $ss_csv_array);
			}
		}else if(mb_substr($str_csv,0,2) == SI_CODE){
			$tmp_arr[6] = 0;
			for($i=1; $i<32; $i++) $tmp_arr[6+$i] = 0;
		}else if(mb_substr($str_csv,0,2) == IY_CODE){
			if($tmp_arr[6] == ''){
				//$comment_data = preg_replace("/\r\n/u", "", $str_csv);
				//合剤処理の場合。改行コードでレコードごとにわけ、最終行を取得
				$iy_csv_array = preg_split("/\r\n/", $str_csv);
				//$last_recode = $iy_csv_array[count($iy_csv_array)-2];
				//$tmp_arr = explode(',', $last_recode);
				//$tmp_arr[6] = 0;
				//$iy_csv_array[count($iy_csv_array)-2] = implode(',', $tmp_arr);

				for($h=0; $h<(count($iy_csv_array)-1); $h++){
					$tmp_arr = explode(',', $iy_csv_array[$h]);
					$tmp_arr[6] = 0;
					for($i=1; $i<32; $i++) $tmp_arr[7+$i] = 0;
					$iy_csv_array[$h] = implode(',', $tmp_arr);
				}
//print_r($iy_csv_array);

				return implode(CSV_BREAK, $iy_csv_array);
			}else{
				$tmp_arr[6] = 0;
				for($i=1; $i<32; $i++) $tmp_arr[7+$i] = 0;
			}
		}else if(mb_substr($str_csv,0,2) == TO_CODE){
			$tmp_arr[13] = 0;
			for($i=1; $i<32; $i++) $tmp_arr[13+$i] = 0;
		}else if(mb_substr($str_csv,0,2) == CO_CODE){
			//突き合わせを実行しない...
		}
		$check_str = implode(',', $tmp_arr);
		return $check_str;
	}


	//ＣＳＶ整形
	function create_csv($arr){
		$tmp_csv = '';
		$num = count($arr);
		$cnt = 0;
		foreach($arr as $key => $val){
			$cnt++;
			if($cnt == $num){
				$tmp_csv .= $val;
			}else{
				$tmp_csv .= $val.DELIMITER;
			}
		}
		return mb_convert_encoding($tmp_csv.CSV_BREAK, CSV_ENCODE, SYS_ENCODE);
	}


    /*
     * 加算コードのソート
     * （１）１桁目Ｃ,Ａ,Ｂ,Ｄ,Ｅ の順
     * （２）処置マスタ「加算記録順」の昇順
     * （３）下3桁数値の昇順
     *  ※例外：「AM004」だけは１桁目ＣとＡの間に記録する
     */
    function sort_kasan_code($data){
        $map = array('C' => '1', 'A' => '3', 'B' => '4', 'D' => '5', 'E' => '6');

        //ソートキーの作成
        for ($i = 0; $i < count($data); $i++) {
            $code = $data[$i]['code'];
            $sort = $data[$i]['sort'];

            $top = substr($code, 0, 1);                //codeの頭のアルファベットを切り出す
            $first = $map[$top];                    //マッピングされた数値に変換(第1ソートキー)
            if ($first == '') $first = 9;            //A-E以外は9にしておく
            if ($code == 'AM004') $first = 2;        //AM004はCの最後、Aの最初の位置にくる

            $second = sprintf("%08d", $sort);        //sortを0フィルで8桁に(第2ソートキー)

            $third = substr($code, -3);                //codeの下3桁(第3ソートキー)

            $key = "$first-$second-$third";
            $data[$i]['key'] = $key;
        }

        //code順に昇順でソート
        foreach ($data as $index => $value) {
            $key_id[$index] = $value['key'];
        }
        array_multisort($key_id, SORT_ASC, $data);

        return $data;
    }

	//加算コード整形（並び替えあり）
	function ajust_kasan_code_with_sort($kasan_array, $kasan_num_array){
debug_dump($kasan_array,'>>>$kasan_array');

//		$add_code_key = array('C', 'A', 'B', 'D', 'E');
//		$tmp_array = array();
//		foreach($kasan_array as $key => $kasan_data){
//            $kasan_code = $kasan_data->code;
//			if($kasan_code != ''){
//				$tmp_array[substr($kasan_code,0,1)][substr($kasan_code,-3)] = array('kasan_code' => $kasan_code, 'kasan_num' => $kasan_num_array[$key]);
//			}
//		}
//
//		$cnt = 0;
//		foreach($add_code_key as $code_key){
//			ksort($tmp_array[$code_key]);
//			foreach($tmp_array[$code_key] as $data){
//				$master[$cnt] = $data;
//				$cnt++;
//			}
//		}

        //KasanData ⇔ code+sort+num

        $unsort_arr = array();
        foreach($kasan_array as $key => $val){  //val は　KasanDataクラスの想定
            if($val != null) {
                $unsort_arr[] = array('code' => $val->code, 'num' => $kasan_num_array[$key], 'sort' => $val->sort);
            }
        }

        //TODO kasan_array を並べ替えて、ソートなしの整形に渡す
debug_dump($unsort_arr, '$unsort_arr');
        $sorted_arr = sort_kasan_code($unsort_arr);
debug_dump($sorted_arr, '$sorted_arr');

        //reset
        $kasan_array = array();
        $kasan_num_array = array();

        foreach($sorted_arr as $key => $val){
            $kasan_data = new KasanData();
            $kasan_data->code = $val['code'];
            $kasan_data->sort = $val['sort'];

            $kasan_array[] = $kasan_data;
            $kasan_num_array[] = $val['num'];
        }
debug_dump($kasan_array, '>>>>>>$kasan_array');
debug_dump($kasan_num_array, '>>>>>>$kasan_num_array');

		return ajust_kasan_code_without_sort($kasan_array, $kasan_num_array);
	}


	//加算コード整形（並び替えなし）
	function ajust_kasan_code_without_sort($kasan_array, $kasan_num_array){
		$cnt = 0;
		foreach($kasan_array as $key => $kasan_data){
			if($kasan_data != null){
				$data = array('kasan_code' => $kasan_data->code, 'kasan_num' => $kasan_num_array[$key]);
				$master[$cnt] = $data;
				$cnt++;
			}
		}
		for($i=$cnt; $i<35; $i++){
			$master[$i] = array('kasan_code' => '', 'kasan_num' => '');
		}
		return $master;
	}

	//公費レコード作成
	function create_ko($codes, $jitu_nissu, $gokei, $kohi_futankin){

		$csv = '';
		foreach($codes as $code_array){
			$ko_arr = array();
			$ko_arr[] = KO_CODE;
			//$ko_arr[] = str_pad($code_array['futan_no'], 8, '0', STR_PAD_LEFT);
			$tmp_futan_no = str_replace(' ', '0', $code_array['futan_no']);
			if(mb_strlen($tmp_futan_no, SYS_ENCODE) < 8){
				$tmp_futan_no = str_pad($tmp_futan_no, 8, '0', STR_PAD_LEFT);
			}
			$ko_arr[] = $tmp_futan_no;
			$ko_arr[] = str_pad($code_array['jyukyu_no'], 7, '0', STR_PAD_LEFT);
			$ko_arr[] = '';
			$ko_arr[] = $jitu_nissu;		//診療実日数
			$ko_arr[] = $gokei;				//合計点数

			if($kohi_futankin > 0){
				$ko_arr[] = $kohi_futankin;			//公費負担金額
			}else{
				$ko_arr[] = '';
			}
			$ko_arr[] = '';
			$ko_arr[] = '';
			$ko_arr[] = '';
			$csv .= create_csv($ko_arr);
		}
		return $csv;
	}

	//部位ステータスを合併させる
	function merge_bui($old, $new_bui){
		$tmp_o = array();
		$tmp_n = array();
		for($i=0; $i<26; $i++){
			$tmp_o[] = mb_substr($old, $i, 1);
			$tmp_n[] = mb_substr($new_bui, $i, 1);
		}

		//マージ相手にすでに値があれば上書きしない。
		//→大きい値の方を有効とする（紙レセプトと同様のロジック）に変更（2012/2/24 c.inoue）
		//  ※ただし、欠損（=9）は除外する
		for($i=0; $i<26; $i++){
			if($tmp_o[$i] == 9)$tmp_o[$i] = 0;
			if($tmp_n[$i] == 9)$tmp_n[$i] = 0;

			if($tmp_o[$i] < $tmp_n[$i]){
				$tmp_o[$i] = $tmp_n[$i];
			}
		}
		return implode('', $tmp_o);
	}

	//MT関連の歯式コードをチェック。部位が他のレコードに完全に含まれる場合はマージさせる。
	function merge_mt($mt_list, $reset_list){
		$copy_mt_list = $mt_list;

		foreach($mt_list as $id => $sisikiData){
			$sisiki = $sisikiData['sisiki'];
			$syobyo = $sisikiData['syobyo'];

			$s_list = array();
			//歯式は6文字固定長。
			$len = mb_strlen($sisiki, SYS_ENCODE);
			for($i=0; $i<($len/6); $i++) $s_list[] = mb_substr($sisiki, $i*6, 6, SYS_ENCODE);

			//全チェックを実施(自分自身は別のレコードに完全に含まれるか否か)
			foreach($copy_mt_list as $cid => $c_sisikiData){
				if(array_search($cid, $reset_list) !== false) continue;		//すでに削除対象となっているレコードなら処理スキップ
				if($cid == $id) continue;									//自分自身も処理スキップ
				if($sisikiData['heizon_cnt'] != $c_sisikiData['heizon_cnt']) continue;					//併存傷病数が違うなら別病名として処理スキップ
				if($sisikiData['byotai_iko_number'] != $c_sisikiData['byotai_iko_number']) continue;	//病態移行番号が違うなら処理スキップ

				$c_sisiki = $c_sisikiData['sisiki'];
				$c_syobyo = $c_sisikiData['syobyo'];
				//同傷病コード以外のレコードは出力
				if($c_syobyo != $syobyo) continue;

				$c_s_list = array();
				$len = mb_strlen($c_sisiki, SYS_ENCODE);
				for($i=0; $i<($len/6); $i++) $c_s_list[] = mb_substr($c_sisiki, $i*6, 6, SYS_ENCODE);
				if(count(array_diff($s_list, $c_s_list)) == 0){
					$reset_list[] = $id;
					break;
				}
			}
		}
		return $reset_list;
	}

	//レセプト種別判断用チェックメソッド　2013/01/04
	function checkReceiptType($hokenja_no, $kouhi_flg, $futan_type, $kohi_futan_no1, $kohi_futan_no2){
		//ＫＯレコードがあるかどうか
		if($kouhi_flg == '' || $kouhi_flg == 0){
			return 0;
		}else if($kouhi_flg == 1){
			if($futan_type != 1 && $kohi_futan_no1 != '' && $kohi_futan_no2 != ''){
				if($hokenja_no != ''){
					return 1;
				}else{
					return 2;
				}
			}
		}
		return 3;
	}

	//特例月のチェック
	function specialCaseMonthCheck($birth, $startdate){
		$special_case_flag = false;
		//条件：受診日が「患者が７５才に達した月(暦月)」かつ「その患者の誕生日が２日以降の場合(誕生日が１日の場合は除く)」
		list($by, $bm, $bd) = explode('/', $birth);
		list($ty, $tm, $td) = explode('/', $startdate);
		if($td != 1) $td = 1;	//ＣＳＶ出力対象月の1日時点での年齢をチェックとする
		$age = $ty - $by;
		if($tm * 100 + $td < $bm * 100 + $bd) $age--;

		//CSV出力対象月が誕生月であり、誕生日が1日ではなく、1日時点で74歳の人（その月のうちに75歳になる人）
		if($age == 74 && $bd != 1 && $bm == $tm){
			$special_case_flag = true;
		}
		return $special_case_flag;
	}

	//公費負担金　記録例外かどうかのチェック  <#2632>
	// 8桁の保険者番号で上2桁が「39」の場合と、
	// 誕生日が1944年4月2日以降の場合は上記の仕様に対して例外
	//
	// true：例外扱いとする（HOレコードに記録しない）
	// false：例外ではない（HOレコードに記録する）
	function kohiExceptionCheck($hokenja_no, $birth){
		$is_exception = false;
		if(mb_substr($hokenja_no,0,2) == "39"){
			if(strlen($hokenja_no) == 8){
				$is_exception = true;
			}
		}
		list($by, $bm, $bd) = explode('/', $birth);
		$birth = $by . sprintf("%02d",$bm) . sprintf("%02d",$bd);

		if($birth >= '19440402'){
			$is_exception = true;
		}

		return $is_exception;
	}

    //就学かどうかの判断（就学ならtrue、未就学ならfalse）
    function isSyugaku($birthday, $startdate){
        $is_syugaku = false;
        $birthday_age6 = date("Y/m/d", strtotime("+6 year", strtotime($birthday)));   //満6歳の誕生日

        //誕生日が4月1日より前かを判断し、就学年月日を算出 #3431で再修正（4/2→4/1）
        $syugaku = "";
        if (strtotime($birthday) <= strtotime(substr($birthday, 0, 4) . "/04/01")) {  //4月1日生まれは早生まれ
            $syugaku = substr($birthday_age6, 0, 4) . "/04/01";
        } else {
            $syugaku = date("Y", strtotime("+1 year", strtotime($birthday_age6))) . "/04/01";
        }
        //就学日が、出力開始日以下なら未就学と判断
        if(strtotime($syugaku) > strtotime($startdate)){
            $is_syugaku = false;
        }else{
            $is_syugaku = true;
        }
        return $is_syugaku;
    }

/*
 * 加算コードのデータ格納用クラス
 */
class KasanData{
    public $code;
    public $suryo;  //用意したものの、2015.03.31時点ではまだ未使用
    public $sort;
}

/*
 * 処置マスタから診療行為コード1件分のSS加算コード関連を抽出し格納しておくクラス
 */
class SS {
    public $kizami;
    public $code;
    public $suryo;
    public $org_suryo;

    public $tensu;
    public $bui_code;
    public $check_digit;

    public $sort;
}

?>
