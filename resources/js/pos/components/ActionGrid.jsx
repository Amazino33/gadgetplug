import { buildLabel } from '../lib/build';
import { fmt } from '../lib/format';

const Btn = ({ label, hotkey, color = 'default', disabled = false, onClick, wide = false }) => {
    const colors = {
        default: 'bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700',
        green:   'bg-[#068B03] text-white hover:bg-[#057002] shadow-md',
        orange:  'bg-[#F97316] text-white hover:bg-[#ea6c0a] shadow-md',
        blue:    'bg-blue-50 dark:bg-blue-900/30 border border-blue-200 dark:border-blue-800 text-blue-600 dark:text-blue-400 hover:bg-blue-100 dark:hover:bg-blue-900/50',
        gray:    'bg-gray-100 dark:bg-gray-800 border border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-400 hover:bg-gray-200 dark:hover:bg-gray-700',
        red:     'bg-red-50 dark:bg-red-900/30 border border-red-200 dark:border-red-800 text-red-600 dark:text-red-400 hover:bg-red-100 dark:hover:bg-red-900/50'
    };

    return (
        <button
            onClick={onClick}
            disabled={disabled}
            className={`
                flex flex-col items-center justify-center gap-0.5 rounded-xl transition-all active:scale-95
                ${wide ? 'col-span-2' : ''}
                ${colors[color]}
                ${disabled ? 'opacity-40 cursor-not-allowed' : 'cursor-pointer'}
                p-2 h-14
            `}
        >
            {hotkey ? (
                <kbd className="px-1.5 py-0.5 border border-gray-300 dark:border-gray-600 rounded bg-white dark:bg-gray-800 text-[10px] font-mono text-gray-500 dark:text-gray-400 shadow-sm leading-none tracking-wider mb-0.5">
                    {hotkey}
                </kbd>
            ) : (
                <span className="h-[18px]"></span>
            )}
            <span className="text-xs font-semibold text-center leading-tight">{label}</span>
        </button>
    );
};

export default function ActionGrid({
    cartEmpty, noSelection,
    onDeleteItem, onSearch, onChangeQty, onNewSale,
    onDiscount, onCustomer,
    onQuickCash, onQuickPOS, onQuickTransfer, onSuspend, onPayment, onVoid,
    onZReport,
    onCashUp, onExpense, onReturn, onSubmitCash, onPickings,
    pendingSales = [], onViewPending, pendingError,
    
    // Totals
    subtotal, discountAmount, vatAmount, total, VAT_ENABLED, VAT_RATE
}) {
    return (
        <div className="w-72 shrink-0 bg-[#F9FAFB] dark:bg-gray-950 border-l border-gray-200 dark:border-gray-800 p-3 flex flex-col gap-3 overflow-y-auto h-full">

            {/* Cart / Row Actions */}
            <div>
                <p className="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-2 px-1">Cart / Row Actions</p>
                <div className="grid grid-cols-2 gap-2">
                    <Btn label="Delete Item"     hotkey="Del" color="red" disabled={noSelection}  onClick={onDeleteItem} />
                    <Btn label="Change Qty"      hotkey="F4"  disabled={noSelection}  onClick={onChangeQty} />
                    <Btn label="Discount"        hotkey="F2"  disabled={cartEmpty}    onClick={onDiscount} />
                    <Btn label="Customer"        hotkey="F6"                          onClick={onCustomer} />
                    <Btn label="New Sale"        hotkey="F8"                          onClick={onNewSale} />
                    <Btn label="Return"          hotkey=""    color="gray"            onClick={onReturn} />
                    <Btn label="Pickings"        hotkey=""    color="blue"            wide={true} onClick={onPickings} />
                </div>
            </div>



            {/* Suspend & Void */}
            <div>
                <p className="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-2 px-1">Hold & Void</p>
                <div className="grid grid-cols-2 gap-2">
                    <button
                        onClick={onSuspend}
                        disabled={cartEmpty}
                        className={`
                            flex flex-col items-center justify-center gap-1 h-14 rounded-xl border-2 border-orange-200 dark:border-orange-900 bg-orange-50 dark:bg-orange-950/40 text-[#F97316]
                            transition-all active:scale-95
                            ${cartEmpty ? 'opacity-40 cursor-not-allowed' : 'hover:bg-orange-100 dark:hover:bg-orange-900 cursor-pointer'}
                        `}
                    >
                        <kbd className="px-1.5 py-0.5 border border-orange-300 dark:border-orange-700 rounded bg-white dark:bg-gray-800 text-[10px] font-mono shadow-sm">F9</kbd>
                        <span className="text-[11px] font-bold">Suspend Sale</span>
                    </button>
                    <button
                        onClick={onVoid}
                        disabled={cartEmpty}
                        className={`
                            flex flex-col items-center justify-center gap-1 h-14 rounded-xl bg-gray-100 dark:bg-gray-800 text-gray-500 dark:text-gray-400
                            transition-all active:scale-95
                            ${cartEmpty ? 'opacity-40 cursor-not-allowed' : 'hover:bg-gray-200 dark:hover:bg-gray-700 cursor-pointer'}
                        `}
                    >
                        <kbd className="px-1.5 py-0.5 border border-gray-300 dark:border-gray-600 rounded bg-white dark:bg-gray-800 text-[10px] font-mono shadow-sm">Esc</kbd>
                        <span className="text-[11px] font-bold">Void Order</span>
                    </button>
                </div>
                {pendingSales.length > 0 && (
                    <button
                        onClick={onViewPending}
                        className="w-full mt-2 flex items-center justify-between gap-2 rounded-xl border border-orange-200 dark:border-orange-900 bg-orange-50 dark:bg-orange-950/40 px-3 py-2.5 hover:bg-orange-100 dark:hover:bg-orange-900 transition-colors shadow-sm"
                    >
                        <div className="flex items-center gap-2">
                            <span className="flex items-center justify-center w-5 h-5 rounded-full bg-[#F97316] text-white text-[10px] font-bold">
                                {pendingSales.length}
                            </span>
                            <span className="text-xs font-bold text-[#F97316]">
                                Suspended
                            </span>
                        </div>
                        <span className="text-[10px] font-bold text-orange-400">View →</span>
                    </button>
                )}
            </div>

            <div className="flex-1"></div>

            {/* Payment & Tender */}
            <div className="mt-auto bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-xl p-3 shadow-sm">
                
                <p className="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-2 px-1">Tender</p>
                <div className="grid grid-cols-3 gap-2 mb-2">
                    <Btn label="Cash" hotkey="F12" disabled={cartEmpty} onClick={onQuickCash} />
                    <Btn label="POS"  hotkey="F11" disabled={cartEmpty} onClick={onQuickPOS} />
                    <Btn label="TFER" hotkey="F7"  disabled={cartEmpty} onClick={onQuickTransfer} />
                </div>
                
                <button
                    onClick={onPayment}
                    disabled={cartEmpty}
                    className={`
                        w-full h-14 rounded-xl flex items-center justify-between px-4 mt-2
                        bg-[#068B03] text-white shadow-lg border border-[#057002]
                        transition-all active:scale-95
                        ${cartEmpty ? 'opacity-40 cursor-not-allowed' : 'hover:bg-[#057002] cursor-pointer'}
                    `}
                >
                    <div className="flex flex-col items-start leading-tight">
                        <span className="text-base font-bold tracking-wide">PAYMENT</span>
                    </div>
                    <kbd className="px-2 py-1 border border-green-400/50 rounded bg-green-800/40 text-xs font-mono text-white shadow-sm font-bold">
                        F10
                    </kbd>
                </button>

            </div>

            {pendingError && (
                <div className="px-2.5 py-2 rounded-lg bg-red-50 border border-red-200 text-[11px] font-semibold text-red-600">
                    {pendingError}
                </div>
            )}

            <p className="mt-1 text-center text-[10px] font-mono text-gray-300 dark:text-gray-600 select-text" title="Build running on this till">
                build {buildLabel()}
            </p>

        </div>
    );
}
