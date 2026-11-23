<?php
// ============================================================
// Public Real-Time TV / Monitor Queue Display
// ============================================================

require_once __DIR__ . '/../config/config.php';

$bizName = get_setting('business_name', 'Smart City Service Center');
$voiceLang = get_setting('announcement_voice_lang', 'en-US');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Live Queue Board - <?= e($bizName) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="<?= BASE_URL ?>/assets/css/style.css" rel="stylesheet">
    <style>
        body {
            background-color: #0b0f19;
            color: #f8fafc;
            overflow-x: hidden;
            user-select: none;
        }
        .display-header {
            background: #111827;
            border-bottom: 2px solid #1f2937;
        }
        .serving-card {
            background: #1e293b;
            border-radius: 14px;
            border: 1px solid #334155;
            transition: all 0.3s ease;
        }
        .serving-card.is-calling {
            border: 2px solid #f59e0b;
            background: #2d2616;
            animation: pulse-border 1.5s infinite;
        }
        @keyframes pulse-border {
            0% { box-shadow: 0 0 0 0 rgba(245, 158, 11, 0.4); }
            70% { box-shadow: 0 0 0 16px rgba(245, 158, 11, 0); }
            100% { box-shadow: 0 0 0 0 rgba(245, 158, 11, 0); }
        }
        .queue-big-number {
            font-family: 'Courier New', Courier, monospace;
            font-size: 3.8rem;
            font-weight: 900;
            letter-spacing: 2px;
            color: #38bdf8;
        }
        .counter-name-label {
            font-size: 1.6rem;
            font-weight: 700;
            color: #f1f5f9;
        }
        .recent-item {
            background: #1e293b;
            border-left: 4px solid #38bdf8;
            border-radius: 8px;
            padding: 0.75rem 1rem;
            margin-bottom: 0.5rem;
        }
    </style>
</head>
<body class="d-flex flex-column min-vh-100">

    <!-- Top Announcement Bar -->
    <header class="display-header py-3 px-4 d-flex justify-content-between align-items-center shadow">
        <div class="d-flex align-items-center">
            <i class="bi bi-broadcast text-warning fs-2 me-3"></i>
            <div>
                <h3 class="fw-bold mb-0 text-white"><?= e($bizName) ?></h3>
                <small class="text-secondary text-uppercase fw-semibold" style="letter-spacing: 1px;">Customer Service Waiting Lobby</small>
            </div>
        </div>

        <div class="d-flex align-items-center gap-3">
            <button id="audioToggleBtn" class="btn btn-outline-warning btn-sm" onclick="toggleAudio()">
                <i class="bi bi-volume-up-fill me-1"></i> Sound: <span id="audioStatusText">Ready</span>
            </button>
            <div class="bg-dark px-3 py-2 rounded-3 border border-secondary text-center">
                <span id="displayClock" class="fs-4 fw-bold text-white font-monospace">--:--:--</span>
            </div>
        </div>
    </header>

    <!-- Main Live Queue Display -->
    <main class="container-fluid px-4 py-4 flex-grow-1">
        <div class="row g-4 h-100">

            <!-- Left 8 Columns: Counters & Serving Queues -->
            <div class="col-lg-8">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h4 class="fw-bold text-white mb-0">
                        <i class="bi bi-arrow-right-circle-fill text-success me-2"></i>Now Serving & Calling
                    </h4>
                    <span class="badge bg-secondary text-light">Automatic Live Sync</span>
                </div>

                <div id="servingGrid" class="row g-3">
                    <!-- Populated dynamically via AJAX -->
                    <div class="col-12 text-center py-5 text-muted">
                        <div class="spinner-border text-primary me-2" role="status"></div> Loading queue status...
                    </div>
                </div>
            </div>

            <!-- Right 4 Columns: Recently Called & Waiting Summary -->
            <div class="col-lg-4">
                
                <!-- Recently Called Section -->
                <div class="card bg-transparent border-0 mb-4">
                    <h5 class="fw-bold text-white mb-3">
                        <i class="bi bi-clock-history text-info me-2"></i>Recently Called
                    </h5>
                    <div id="recentCallsList">
                        <!-- Populated via AJAX -->
                        <div class="text-muted small">Listening for calls...</div>
                    </div>
                </div>

                <!-- Waiting Summary Section -->
                <div class="card bg-dark border-secondary rounded-4 p-3 shadow-sm">
                    <h6 class="fw-bold text-white mb-3 text-uppercase small" style="letter-spacing: 0.5px;">
                        <i class="bi bi-people-fill text-warning me-2"></i>Waiting Line Summary
                    </h6>
                    <div id="waitingSummaryList" class="d-flex flex-column gap-2">
                        <!-- Populated via AJAX -->
                    </div>
                </div>

            </div>

        </div>
    </main>

    <!-- Footer Notice -->
    <footer class="py-2 px-4 bg-dark border-top border-secondary text-center text-muted small">
        <span>Please have your identification and relevant documents prepared when your queue number is announced.</span>
    </footer>

    <!-- Logic Engine Script -->
    <script>
        let lastAnnouncedCallId = null;
        let audioEnabled = true;
        const voiceLang = "<?= e($voiceLang) ?>";

        // Web Audio API Chime Synthesizer
        let audioCtx = null;
        function playChime() {
            try {
                if (!audioCtx) {
                    audioCtx = new (window.AudioContext || window.webkitAudioContext)();
                }
                const now = audioCtx.currentTime;
                // High Tone
                const osc1 = audioCtx.createOscillator();
                const gain1 = audioCtx.createGain();
                osc1.type = 'sine';
                osc1.frequency.setValueAtTime(659.25, now); // E5
                gain1.gain.setValueAtTime(0.3, now);
                gain1.gain.exponentialRampToValueAtTime(0.001, now + 0.5);
                osc1.connect(gain1);
                gain1.connect(audioCtx.destination);
                osc1.start(now);
                osc1.stop(now + 0.5);

                // Low Tone
                const osc2 = audioCtx.createOscillator();
                const gain2 = audioCtx.createGain();
                osc2.type = 'sine';
                osc2.frequency.setValueAtTime(523.25, now + 0.25); // C5
                gain2.gain.setValueAtTime(0.3, now + 0.25);
                gain2.gain.exponentialRampToValueAtTime(0.001, now + 0.85);
                osc2.connect(gain2);
                gain2.connect(audioCtx.destination);
                osc2.start(now + 0.25);
                osc2.stop(now + 0.85);
            } catch(e) {
                console.warn("Audio Context error: ", e);
            }
        }

        // Speech Synthesis Announcer
        function announceCall(queueNumber, counterName) {
            if (!audioEnabled || !('speechSynthesis' in window)) return;
            
            // Format queue letters cleanly: e.g. A025 -> "A, 0 2 5"
            const formattedQueue = queueNumber.split('').join(' ');
            const text = `Queue Number ${formattedQueue}, please proceed to ${counterName}`;
            
            // First play chime, then speak after brief delay
            playChime();
            setTimeout(() => {
                const utterance = new SpeechSynthesisUtterance(text);
                utterance.lang = voiceLang;
                utterance.rate = 0.9;
                utterance.pitch = 1.0;
                window.speechSynthesis.speak(utterance);
            }, 600);
        }

        function toggleAudio() {
            audioEnabled = !audioEnabled;
            const btnText = document.getElementById('audioStatusText');
            btnText.textContent = audioEnabled ? 'Active' : 'Muted';
            document.getElementById('audioToggleBtn').className = audioEnabled ? 'btn btn-outline-warning btn-sm' : 'btn btn-outline-secondary btn-sm';
            if (audioEnabled && !audioCtx) {
                audioCtx = new (window.AudioContext || window.webkitAudioContext)();
            }
        }

        // Live Clock
        setInterval(() => {
            const now = new Date();
            document.getElementById('displayClock').textContent = now.toLocaleTimeString();
        }, 1000);

        // Fetch Live Display Data
        async function updateDisplay() {
            try {
                const response = await fetch('<?= BASE_URL ?>/api/display_data.php');
                if (!response.ok) return;
                const data = await response.json();

                // 1. Render Serving Grid
                const grid = document.getElementById('servingGrid');
                if (data.currently_serving && data.currently_serving.length > 0) {
                    let html = '';
                    data.currently_serving.forEach(item => {
                        const isCalling = item.status === 'Called';
                        const queueNum = item.queue_number || '---';
                        const svcName = item.service_name || 'Counter Idle';
                        const badgeClass = isCalling ? 'bg-warning text-dark' : (item.status === 'Serving' ? 'bg-success text-white' : 'bg-secondary text-light');
                        const statusText = item.status ? item.status.toUpperCase() : 'OPEN';

                        html += `
                            <div class="col-md-6">
                                <div class="serving-card p-4 text-center ${isCalling ? 'is-calling' : ''}">
                                    <div class="d-flex justify-content-between align-items-center mb-2">
                                        <div class="counter-name-label">${escapeHtml(item.counter_name)}</div>
                                        <span class="badge ${badgeClass} px-3 py-1">${statusText}</span>
                                    </div>
                                    <div class="queue-big-number ${isCalling ? 'text-warning' : (item.queue_number ? 'text-primary' : 'text-muted')}">
                                        ${escapeHtml(queueNum)}
                                    </div>
                                    <div class="text-secondary small mt-1">${escapeHtml(svcName)}</div>
                                </div>
                            </div>
                        `;
                    });
                    grid.innerHTML = html;
                } else {
                    grid.innerHTML = '<div class="col-12 text-center py-5 text-muted">No counters currently operating.</div>';
                }

                // 2. Render Recently Called
                const recentContainer = document.getElementById('recentCallsList');
                if (data.recently_called && data.recently_called.length > 0) {
                    let rHtml = '';
                    data.recently_called.forEach(rc => {
                        rHtml += `
                            <div class="recent-item d-flex justify-content-between align-items-center">
                                <div>
                                    <span class="fs-4 fw-bold font-monospace text-warning me-2">${escapeHtml(rc.queue_number)}</span>
                                    <span class="text-white fw-semibold">${escapeHtml(rc.counter_name)}</span>
                                </div>
                                <span class="badge bg-dark border border-secondary text-secondary small">${rc.call_type}</span>
                            </div>
                        `;
                    });
                    recentContainer.innerHTML = rHtml;

                    // Trigger Voice & Sound Announcement on New Call Event
                    const newest = data.recently_called[0];
                    if (newest && lastAnnouncedCallId !== null && newest.call_id !== lastAnnouncedCallId) {
                        announceCall(newest.queue_number, newest.counter_name);
                    }
                    if (newest) {
                        lastAnnouncedCallId = newest.call_id;
                    }
                } else {
                    recentContainer.innerHTML = '<div class="text-muted small">No recent calls recorded today.</div>';
                }

                // 3. Render Waiting Summary
                const summaryContainer = document.getElementById('waitingSummaryList');
                if (data.waiting_summary && data.waiting_summary.length > 0) {
                    let sHtml = '';
                    data.waiting_summary.forEach(ws => {
                        sHtml += `
                            <div class="d-flex justify-content-between align-items-center p-2 rounded bg-secondary bg-opacity-10">
                                <div>
                                    <span class="badge bg-primary me-2">${escapeHtml(ws.service_code)}</span>
                                    <span class="small fw-semibold text-light">${escapeHtml(ws.service_name)}</span>
                                </div>
                                <span class="badge bg-warning text-dark font-monospace fs-6">${ws.waiting_count}</span>
                            </div>
                        `;
                    });
                    summaryContainer.innerHTML = sHtml;
                }

            } catch (err) {
                console.error("Display sync error: ", err);
            }
        }

        function escapeHtml(str) {
            if (!str) return '';
            return str.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;").replace(/'/g, "&#039;");
        }

        // Initialize and poll every 2.5 seconds
        updateDisplay();
        setInterval(updateDisplay, 2500);
    </script>
</body>
</html>
