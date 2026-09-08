<?php

namespace App\Models\DtPay;

use Illuminate\Database\Eloquent\Model;

use Illuminate\Support\Facades\DB;

class DtPayIncrementModel extends Model
{
    const TYPE_SHIPMENT = 'shipment';

    protected $table = 'dt_pay_increments';

    public function format($row = false){

       if($row){
			$row->added_on_formatted = date("d M Y", strtotime($row->added_on));
			$row->title = clean_display($row->title);
		}

        return $row;
    }

    public function update_increment($type){
		$row = $this->last_increment($type);

		if($row !== false){
			$increment = $row->increment + 1;

            DB::table($this->getTable())
            ->where('type', $type)
            ->update([
                'increment' => $increment,
                'updated_on' => date('Y-m-d H:i:s'),
            ]);
        }
	}

    public function last_increment($type){

        $row = DB::table($this->getTable())->where('type', $type);
		if($row->count() > 0){
			$row = $row->first();
			return $row;
		}

		return false;
	}
}
