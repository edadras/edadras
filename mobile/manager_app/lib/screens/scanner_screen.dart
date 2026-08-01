import 'package:flutter/material.dart';
import 'package:gymflow_core/gymflow_core.dart';
import 'package:mobile_scanner/mobile_scanner.dart';

/// The gate. The camera reads a member's QR badge, the API decides, and the
/// screen answers with a green or red card the receptionist can read across
/// the desk.
class ScannerScreen extends StatefulWidget {
  const ScannerScreen({super.key});

  @override
  State<ScannerScreen> createState() => _ScannerScreenState();
}

class _ScannerScreenState extends State<ScannerScreen> {
  final MobileScannerController _controller = MobileScannerController(
    detectionSpeed: DetectionSpeed.noDuplicates,
    formats: const [BarcodeFormat.qrCode],
  );

  CheckInResult? _result;
  bool _busy = false;
  bool _checkingOut = false;
  DateTime? _lastScan;

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  /// A badge held up to the lens fires repeatedly; one scan per two seconds
  /// is enough, and it stops a nervous hand from double charging a quota.
  bool get _isCoolingDown =>
      _lastScan != null && DateTime.now().difference(_lastScan!).inMilliseconds < 2000;

  Future<void> _onDetect(BarcodeCapture capture) async {
    final token = capture.barcodes.firstOrNull?.rawValue;

    if (token == null || _busy || _isCoolingDown) return;

    _lastScan = DateTime.now();
    await _submit(token);
  }

  Future<void> _submit(String token) async {
    final api = SessionScope.of(context).api;
    final t = TranslationsScope.of(context);

    setState(() {
      _busy = true;
      _result = null;
    });

    try {
      final path = _checkingOut ? '/attendance/check-out' : '/attendance/check-in';
      final response = await api.post(path, body: {'qr_token': token, 'method': 'qr'});

      setState(() {
        _result = CheckInResult.fromJson(
          (response as Map).cast<String, dynamic>(),
          allowed: true,
        );
      });
    } on ApiException catch (error) {
      setState(() {
        _result = CheckInResult.refused(
          error.message.isEmpty ? t.t('checkin.unknown_code', 'Unknown code.') : error.message,
          reason: error.reason,
        );
      });
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final t = TranslationsScope.of(context);

    return Column(
      children: [
        Padding(
          padding: const EdgeInsets.fromLTRB(16, 12, 16, 8),
          child: Row(
            children: [
              Expanded(
                child: Text(
                  t.t('nav.check_in', 'Check-in'),
                  style: Theme.of(context).textTheme.headlineSmall,
                ),
              ),
              // One toggle instead of two buttons: the desk is either taking
              // people in or letting them out.
              SegmentedButton<bool>(
                segments: [
                  ButtonSegment(value: false, label: Text(t.t('checkin.enter', 'In'))),
                  ButtonSegment(value: true, label: Text(t.t('checkin.exit', 'Out'))),
                ],
                selected: {_checkingOut},
                onSelectionChanged: (value) => setState(() => _checkingOut = value.first),
              ),
            ],
          ),
        ),

        Expanded(
          child: Padding(
            padding: const EdgeInsets.symmetric(horizontal: 16),
            child: ClipRRect(
              borderRadius: BorderRadius.circular(AppTheme.glassRadius),
              child: Stack(
                fit: StackFit.expand,
                children: [
                  MobileScanner(controller: _controller, onDetect: _onDetect),
                  const _ScannerReticle(),
                  if (_busy)
                    const ColoredBox(
                      color: Colors.black45,
                      child: Center(child: CircularProgressIndicator(color: AppTheme.brand)),
                    ),
                ],
              ),
            ),
          ),
        ),

        Padding(
          padding: const EdgeInsets.fromLTRB(16, 14, 16, 24),
          child: _ResultCard(result: _result),
        ),
      ],
    );
  }
}

/// The corner brackets that tell the eye where to hold the badge.
class _ScannerReticle extends StatelessWidget {
  const _ScannerReticle();

  @override
  Widget build(BuildContext context) {
    return IgnorePointer(
      child: Center(
        child: Container(
          width: 220,
          height: 220,
          decoration: BoxDecoration(
            border: Border.all(color: AppTheme.brand.withValues(alpha: 0.85), width: 3),
            borderRadius: BorderRadius.circular(28),
          ),
        ),
      ),
    );
  }
}

class _ResultCard extends StatelessWidget {
  const _ResultCard({required this.result});

  final CheckInResult? result;

  @override
  Widget build(BuildContext context) {
    final t = TranslationsScope.of(context);

    if (result == null) {
      return GlassCard(
        child: Row(
          children: [
            const Icon(Icons.qr_code_2, color: AppTheme.ink400),
            const SizedBox(width: 12),
            Expanded(
              child: Text(
                t.t('checkin.scan_hint', 'Hold a member badge up to the camera'),
                style: Theme.of(context).textTheme.bodySmall,
              ),
            ),
          ],
        ),
      );
    }

    final allowed = result!.allowed;
    final colour = allowed ? AppTheme.brand : AppTheme.danger;

    return AnimatedSwitcher(
      duration: const Duration(milliseconds: 260),
      child: GlassCard(
        key: ValueKey(result),
        strong: true,
        borderColor: colour.withValues(alpha: 0.55),
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Container(
              width: 52,
              height: 52,
              alignment: Alignment.center,
              decoration: BoxDecoration(
                color: colour.withValues(alpha: 0.18),
                borderRadius: BorderRadius.circular(16),
              ),
              child: Icon(
                allowed ? Icons.check_rounded : Icons.block_rounded,
                color: colour,
                size: 30,
              ),
            ),
            const SizedBox(width: 14),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    result!.message,
                    style: TextStyle(
                      fontSize: 16,
                      fontWeight: FontWeight.w800,
                      color: colour,
                    ),
                  ),
                  if (result!.member != null) ...[
                    const SizedBox(height: 4),
                    Text(
                      '${result!.member!.fullName} · #${result!.member!.code}',
                      style: const TextStyle(color: Colors.white, fontSize: 14),
                    ),
                  ],
                  if (allowed) ...[
                    const SizedBox(height: 6),
                    Wrap(
                      spacing: 8,
                      children: [
                        if (result!.remainingSessions != null)
                          _Pill(
                            label:
                                '${t.t('checkin.sessions_left', 'Sessions')}: ${result!.remainingSessions}',
                          ),
                        if (result!.daysRemaining != null)
                          _Pill(
                            label:
                                '${t.t('checkin.days_left', 'Days')}: ${result!.daysRemaining}',
                          ),
                      ],
                    ),
                  ],
                  // A refusal the desk can fix on the spot deserves a nudge.
                  if (result!.isRenewable) ...[
                    const SizedBox(height: 8),
                    Text(
                      t.t('ai.campaign_expiring_body', 'Offer a renewal at the desk.'),
                      style: Theme.of(context).textTheme.bodySmall,
                    ),
                  ],
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _Pill extends StatelessWidget {
  const _Pill({required this.label});

  final String label;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
      decoration: BoxDecoration(
        color: Colors.white.withValues(alpha: 0.08),
        borderRadius: BorderRadius.circular(999),
      ),
      child: Text(label, style: const TextStyle(fontSize: 12, color: AppTheme.ink200)),
    );
  }
}

extension _FirstOrNull<E> on Iterable<E> {
  E? get firstOrNull => isEmpty ? null : first;
}
