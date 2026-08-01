import 'dart:async';

import 'package:flutter/material.dart';
import 'package:gymflow_core/gymflow_core.dart';
import 'package:url_launcher/url_launcher.dart';

/// Paying online: the member picks an amount or an unpaid invoice, is sent to
/// the club's gateway in the browser, and comes back here. Nothing is counted
/// as paid until the API says the gateway confirmed it, so this screen polls
/// rather than believing the redirect.
class CheckoutScreen extends StatefulWidget {
  const CheckoutScreen({super.key, this.invoice});

  /// The invoice being settled, or null for a wallet top-up.
  final Invoice? invoice;

  @override
  State<CheckoutScreen> createState() => _CheckoutScreenState();
}

enum _Stage { idle, opening, waiting, paid, failed }

class _CheckoutScreenState extends State<CheckoutScreen> with WidgetsBindingObserver {
  final _amountController = TextEditingController();

  _Stage _stage = _Stage.idle;
  String? _gateway;
  bool _live = false;
  int? _paymentId;
  String? _error;
  Timer? _poll;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);

    if (widget.invoice != null) {
      _amountController.text = widget.invoice!.balance.toStringAsFixed(0);
    }

    _loadGateway();
  }

  @override
  void dispose() {
    _poll?.cancel();
    _amountController.dispose();
    WidgetsBinding.instance.removeObserver(this);
    super.dispose();
  }

  /// Coming back from the browser is the moment worth re-checking.
  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed && _stage == _Stage.waiting) {
      _checkStatus();
    }
  }

  Future<void> _loadGateway() async {
    try {
      final response = await SessionScope.of(context).api.get('/me/payment-gateway');

      setState(() {
        _gateway = (response as Map)['gateway'] as String?;
        _live = response['live'] == true;
      });
    } on ApiException {
      // The screen still works; it just cannot name the gateway.
    }
  }

  Future<void> _start() async {
    final amount = double.tryParse(_amountController.text.trim());

    if (amount == null || amount <= 0) {
      setState(() => _error = TranslationsScope.of(context)
          .t('payments.amount_must_be_positive', 'The amount has to be more than zero.'));

      return;
    }

    setState(() {
      _stage = _Stage.opening;
      _error = null;
    });

    try {
      final response = await SessionScope.of(context).api.post('/me/payments/start', body: {
        if (widget.invoice != null) 'invoice_id': widget.invoice!.id,
        'amount': amount,
      });

      _paymentId = ((response as Map)['payment_id'] as num).toInt();

      final url = Uri.parse('${response['redirect_url']}');
      final opened = await launchUrl(url, mode: LaunchMode.externalApplication);

      if (!opened) {
        throw ApiException(
          TranslationsScope.of(context)
              .t('payments.gateway_unavailable', 'The payment gateway is not responding right now.'),
        );
      }

      setState(() => _stage = _Stage.waiting);
      _startPolling();
    } on ApiException catch (error) {
      setState(() {
        _stage = _Stage.failed;
        _error = error.message;
      });
    }
  }

  /// The gateway settles server side; the app just waits to be told.
  void _startPolling() {
    _poll?.cancel();
    _poll = Timer.periodic(const Duration(seconds: 3), (_) => _checkStatus());
  }

  Future<void> _checkStatus() async {
    if (_paymentId == null) return;

    try {
      final response =
          await SessionScope.of(context).api.get('/me/payments/$_paymentId/status');

      final status = (response as Map)['status'];

      if (status == 'paid') {
        _poll?.cancel();
        if (mounted) setState(() => _stage = _Stage.paid);
      } else if (status == 'failed') {
        _poll?.cancel();
        if (mounted) {
          setState(() {
            _stage = _Stage.failed;
            _error = TranslationsScope.of(context)
                .t('payments.failed', 'The payment did not go through.');
          });
        }
      }
    } on ApiException {
      // A dropped poll is not a failure; the next tick tries again.
    }
  }

  @override
  Widget build(BuildContext context) {
    final t = TranslationsScope.of(context);

    return DetailScaffold(
      title: t.t('payments.pay_online', 'Pay online'),
      child: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          if (!_live && _gateway != null)
            GlassCard(
              margin: const EdgeInsets.only(bottom: 16),
              borderColor: AppTheme.warning.withValues(alpha: 0.4),
              child: Row(
                children: [
                  const Icon(Icons.info_outline, color: AppTheme.warning, size: 20),
                  const SizedBox(width: 12),
                  Expanded(
                    child: Text(
                      t.t('payments.sandbox_notice',
                          'This club is on the sandbox gateway, so no money will move.'),
                      style: Theme.of(context).textTheme.bodySmall,
                    ),
                  ),
                ],
              ),
            ),

          if (widget.invoice != null)
            GlassCard(
              margin: const EdgeInsets.only(bottom: 16),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    widget.invoice!.items.isEmpty
                        ? widget.invoice!.number
                        : widget.invoice!.items.first,
                    style: const TextStyle(color: Colors.white, fontWeight: FontWeight.w600),
                  ),
                  const SizedBox(height: 6),
                  Row(
                    children: [
                      Text(t.t('members.due', 'Due'),
                          style: Theme.of(context).textTheme.labelSmall),
                      const Spacer(),
                      MoneyText(widget.invoice!.balance, size: 18),
                    ],
                  ),
                ],
              ),
            ),

          if (_stage == _Stage.paid)
            _Result(
              icon: Icons.check_circle_outline,
              color: AppTheme.brand,
              title: t.t('payments.paid', 'Payment received. Thank you.'),
              actionLabel: t.t('general.done', 'Done'),
              onAction: () => Navigator.of(context).pop(true),
            )
          else if (_stage == _Stage.waiting)
            _Result(
              icon: Icons.hourglass_top,
              color: AppTheme.warning,
              title: t.t('payments.pending', 'Waiting for the gateway.'),
              actionLabel: t.t('payments.check_again', 'Check again'),
              onAction: _checkStatus,
              busy: true,
            )
          else ...[
            GlassCard(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(t.t('finance.amount', 'Amount'),
                      style: Theme.of(context).textTheme.labelSmall),
                  const SizedBox(height: 8),
                  TextField(
                    controller: _amountController,
                    readOnly: widget.invoice != null,
                    keyboardType: const TextInputType.numberWithOptions(decimal: true),
                    style: const TextStyle(
                      color: Colors.white,
                      fontSize: 24,
                      fontWeight: FontWeight.w700,
                    ),
                    decoration: const InputDecoration(hintText: '0'),
                  ),
                  if (widget.invoice == null) ...[
                    const SizedBox(height: 12),
                    Wrap(
                      spacing: 8,
                      children: [
                        for (final amount in const [100000, 250000, 500000, 1000000])
                          ActionChip(
                            label: Text(formatAmount(amount.toDouble())),
                            onPressed: () =>
                                _amountController.text = amount.toString(),
                          ),
                      ],
                    ),
                  ],
                ],
              ),
            ),

            if (_error != null) ...[
              const SizedBox(height: 12),
              Text(_error!, style: const TextStyle(color: AppTheme.danger, fontSize: 13)),
            ],

            const SizedBox(height: 20),
            BrandButton(
              label: _stage == _Stage.opening
                  ? t.t('payments.opening', 'Opening the gateway…')
                  : t.t('payments.pay_now', 'Pay now'),
              onPressed: _stage == _Stage.opening ? null : _start,
            ),

            if (_gateway != null) ...[
              const SizedBox(height: 12),
              Text(
                '${t.t('payments.via', 'Through')} $_gateway',
                textAlign: TextAlign.center,
                style: Theme.of(context).textTheme.labelSmall,
              ),
            ],
          ],
        ],
      ),
    );
  }
}

class _Result extends StatelessWidget {
  const _Result({
    required this.icon,
    required this.color,
    required this.title,
    required this.actionLabel,
    required this.onAction,
    this.busy = false,
  });

  final IconData icon;
  final Color color;
  final String title;
  final String actionLabel;
  final VoidCallback onAction;
  final bool busy;

  @override
  Widget build(BuildContext context) {
    return GlassCard(
      padding: const EdgeInsets.symmetric(horizontal: 20, vertical: 32),
      child: Column(
        children: [
          Icon(icon, color: color, size: 56),
          const SizedBox(height: 16),
          Text(
            title,
            textAlign: TextAlign.center,
            style: const TextStyle(color: Colors.white, fontSize: 16, fontWeight: FontWeight.w600),
          ),
          if (busy) ...[
            const SizedBox(height: 16),
            const SizedBox(
              width: 20,
              height: 20,
              child: CircularProgressIndicator(strokeWidth: 2, color: AppTheme.brand),
            ),
          ],
          const SizedBox(height: 24),
          BrandButton(label: actionLabel, onPressed: onAction),
        ],
      ),
    );
  }
}
