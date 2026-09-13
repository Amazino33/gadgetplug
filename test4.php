<?php try { abort(404); } catch (\Exception $e) { dump(json_encode(["message" => $e->getMessage()])); }
