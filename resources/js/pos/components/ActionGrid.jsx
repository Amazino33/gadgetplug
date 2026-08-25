import { timeAgo } from '../lib/format';

const Btn = ({ label, hotkey, color = 'default', disabled = false, onClick, wide = false }) => {
    const colors = {
        default: 'bg-white border border-gray-200 text-gray-700 hover:bg-gray-50',
        green:   'bg-[#068B03] text-white hover:bg-[#057002] shadow-md',
        orange:  'bg-[#F97316] text-white hover:bg-[#ea6c0a] shadow-md',
        blue:    'bg-blue-500 text-white hover:bg-blue-600',
        gray:    'bg-gray-100 text-gray-500 hover:bg-gray-200',
    };

    return (
        <button
            onClick={onClick}
            disabled={disabled}
            className={`
                flex flex-col items-center justify-center gap-1 rounded-xl transition-all active:scale-95
                ${wide ? 'col-span-2' : ''}
                ${colors[color]}
                ${disabled ? 'opacity-40 cursor-not-allowed' : 'cursor-pointer'}
                p-3 h-16
            `}
        >
            <span className="text-xs font-bold opacity-60">{hotkey}</span>
            <span className="text-xs font-semibold text-center leading-tight">{label}</span>
        </button>
    );
};

export default function ActionGrid({
    cartEmpty, noSelection,
    onDeleteItem, onSearch, onChangeQty, onNewSale,
    onDiscount, onCustomer,
    onQuickCash, onQuickPOS, onSuspend, onPayment, onVoid,
    onZReport, onReturn,
    pendingSales = [], onResumePending, onClearPending, pendingError,
}) {
    return (
        <div className="w-64 shrink-0 bg-[#F9FAFB] border-l border-gray-200 p-3 flex flex-col gap-3 overflow-y-auto">

            {/* Actions */}
            <div>
                <p className="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-2 px-1">Actions</p>
                <div className="grid grid-cols-2 gap-2">
                    <Btn label="Delete Item"     hotkey="Del" disabled={noSelection}  onClick={onDeleteItem} />
                    <Btn label="Search"          hotkey="F3"                          onClick={onSearch} />
                    <Btn label="Change Qty"      hotkey="F4"  disabled={noSelection}  onClick={onChangeQty} />
                    <Btn label="New Sale"        hotkey="F8"                          onClick={onNewSale} />
                </div>
            </div>

            {/* Customer & Cart */}
            <div>
                <p className="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-2 px-1">Customer & Cart</p>
                <div className="grid grid-cols-2 gap-2">
                    <Btn label="Discount"        hotkey="F2"  disabled={cartEmpty}    onClick={onDiscount} />
                    <Btn label="Customer"        hotkey="C"                           onClick={onCustomer} />
                    <Btn label="Return"          hotkey=""    color="gray"            onClick={onReturn} />
                    <Btn label="Z-Report"        hotkey=""    color="gray"            onClick={onZReport} />
                </div>
            </div>

            {/* Payments */}
            <div className="mt-auto">
                <p className="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-2 px-1">Payment</p>
                <div className="grid grid-cols-2 gap-2">
                    <Btn label="Quick Cash"      hotkey="F12" disabled={cartEmpty}    onClick={onQuickCash} />
                    <Btn label="Quick POS"       hotkey="F11" disabled={cartEmpty}    onClick={onQuickPOS} />
                </div>
                <div className="mt-2">
                    <button
                        onClick={onPayment}
                        disabled={cartEmpty}
                        className={`
                            w-full h-20 rounded-xl flex flex-col items-center justify-center gap-1
                            bg-[#068B03] text-white shadow-lg
                            transition-all active:scale-95
                            ${cartEmpty ? 'opacity-40 cursor-not-allowed' : 'hover:bg-[#057002] cursor-pointer'}
                        `}
                    >
                        <span className="text-xs font-bold opacity-70">F10</span>
                        <span className="text-base font-bold">PAYMENT</span>
                    </button>
                </div>
                <div className="mt-2">
                    <button
                        onClick={onVoid}
                        disabled={cartEmpty}
                        className={`
                            w-full h-12 rounded-xl flex flex-col items-center justify-center gap-0.5
                            bg-[#F97316] text-white
                            transition-all active:scale-95
                            ${cartEmpty ? 'opacity-40 cursor-not-allowed' : 'hover:bg-[#ea6c0a] cursor-pointer'}
                        `}
                    >
                        <span className="text-[10px] font-bold opacity-70">Esc</span>
                        <span className="text-sm font-bold">Void Order</span>
                    </button>
                </div>
                <div className="mt-2">
                    <Btn label="Suspend Sale"    hotkey="F9"  disabled={cartEmpty}    onClick={onSuspend} wide={true} />
                </div>

                {pendingError && (
                    <div className="mt-3 px-2.5 py-2 rounded-lg bg-red-50 border border-red-200 text-[11px] font-semibold text-red-600">
                        {pendingError}
                    </div>
                )}

                {pendingSales.length > 0 && (
                    <div className="mt-3 pt-3 border-t border-gray-200">
                        <p className="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-2 px-1">
                            Pending Sales ({pendingSales.length})
                        </p>
                        <div className="space-y-1.5 max-h-52 overflow-y-auto">
                            {pendingSales.map((sale) => (
                                <div key={sale.id}
                                    className="flex items-center gap-2 bg-orange-50 border border-orange-200 rounded-lg px-2.5 py-2">
                                    <button
                                        onClick={() => cartEmpty && onResumePending(sale)}
                                        disabled={!cartEmpty}
                                        className="flex-1 min-w-0 text-left disabled:opacity-50 disabled:cursor-not-allowed"
                                    >
                                        <p className="text-xs font-semibold text-gray-700 truncate">
                                            {sale.label || sale.customer?.name || `Sale #${sale.id}`}
                                        </p>
                                        <p className="text-[10px] text-gray-400">
                                            {sale.cart_data?.items?.length ?? 0} item(s) · {timeAgo(sale.created_at)}
                                        </p>
                                    </button>
                                    <button
                                        onClick={() => onClearPending(sale.id)}
                                        className="text-red-400 hover:text-red-600 text-xs shrink-0 px-1"
                                        aria-label="Discard held sale"
                                    >
                                        ✕
                                    </button>
                                </div>
                            ))}
                        </div>
                        {!cartEmpty && (
                            <p className="text-[10px] text-gray-400 mt-1.5 px-1">
                                Clear or complete the current cart to resume one of these.
                            </p>
                        )}
                    </div>
                )}
            </div>
        </div>
    );
}
