<?php try { App\Models\PosSession::findOrFail(999); } catch (\Exception $e) { dump($e->getMessage()); }
