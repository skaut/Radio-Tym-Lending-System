function startTimer(duration, timerDisplay, statusDisplay) {
	let timer = duration, hours, minutes, seconds;
	let interval = setInterval(function () {
		hours = parseInt(timer / (60 * 60), 10);
		minutes = parseInt((timer - (hours * 60 * 60)) / 60, 10);
		seconds = parseInt(timer % 60, 10);

		hours = hours < 10 ? "0" + hours : hours;
		minutes = minutes < 10 ? "0" + minutes : minutes;
		seconds = seconds < 10 ? "0" + seconds : seconds;

		timerDisplay.textContent = hours + ":" + minutes + ":" + seconds;

		if (--timer < 0) {
			clearInterval(interval); // cleaner solution (:

			timerDisplay.textContent = '';
			statusDisplay.textContent = 'Ready';
		}
	}, 1000);
}
