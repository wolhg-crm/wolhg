package main

// One-shot screenshot capture for /preza product screens.
// Not deployed (scripts/ is excluded from FTP).
//
// Usage:
//   go run ./scripts/preza-screenshots -base http://localhost:8096 -out preza/assets/screens
//
// Login defaults match seed: admin / password

import (
	"context"
	"flag"
	"fmt"
	"log"
	"os"
	"path/filepath"
	"time"

	"github.com/chromedp/chromedp"
)

func main() {
	base := flag.String("base", "http://localhost:8096", "app base URL")
	out := flag.String("out", "preza/assets/screens", "output directory")
	login := flag.String("login", "admin", "CRM login")
	password := flag.String("password", "password", "CRM password")
	church := flag.String("church", "pushkino", "church slug")
	flag.Parse()

	if err := os.MkdirAll(*out, 0o755); err != nil {
		log.Fatal(err)
	}

	opts := append(chromedp.DefaultExecAllocatorOptions[:],
		chromedp.Flag("headless", true),
		chromedp.Flag("disable-gpu", true),
		chromedp.Flag("no-sandbox", true),
		chromedp.Flag("hide-scrollbars", true),
		chromedp.WindowSize(1440, 900),
	)
	allocCtx, cancel := chromedp.NewExecAllocator(context.Background(), opts...)
	defer cancel()
	ctx, cancel := chromedp.NewContext(allocCtx, chromedp.WithLogf(log.Printf))
	defer cancel()
	ctx, cancel = context.WithTimeout(ctx, 4*time.Minute)
	defer cancel()

	loginURL := fmt.Sprintf("%s/%s/login", trimSlash(*base), *church)
	homeURL := fmt.Sprintf("%s/%s/", trimSlash(*base), *church)

	type shot struct {
		name string
		url  string
		wait string
	}

	shots := []shot{
		{name: "homegroup.png", url: trimSlash(*base) + "/homegroup", wait: "body"},
		{name: "dashboard.png", url: homeURL, wait: "body"},
		{name: "care.png", url: homeURL + "care", wait: "body"},
		{name: "regional.png", url: homeURL + "regional", wait: "body"},
		{name: "analytics.png", url: homeURL + "church-analytics", wait: "body"},
		{name: "pwa.png", url: homeURL + "profile", wait: "body"},
	}

	tasks := chromedp.Tasks{
		chromedp.Navigate(loginURL),
		chromedp.WaitVisible(`input[name="login"], input[name="email"], form`, chromedp.ByQuery),
		chromedp.Sleep(500 * time.Millisecond),
	}

	// Support login or email field names used historically.
	tasks = append(tasks,
		chromedp.Evaluate(fmt.Sprintf(`(() => {
			const login = document.querySelector('input[name="login"]') || document.querySelector('input[name="email"]');
			const pass = document.querySelector('input[name="password"]');
			if (login) { login.value = %q; login.dispatchEvent(new Event('input', {bubbles:true})); }
			if (pass) { pass.value = %q; pass.dispatchEvent(new Event('input', {bubbles:true})); }
			const form = document.querySelector('form');
			if (form) form.requestSubmit ? form.requestSubmit() : form.submit();
			return true;
		})()`, *login, *password), nil),
		chromedp.Sleep(2*time.Second),
	)

	if err := chromedp.Run(ctx, tasks); err != nil {
		log.Fatalf("login: %v", err)
	}

	// Capture public page first without relying on auth state for that one —
	// but cookie already set is fine.
	for _, s := range shots {
		var buf []byte
		err := chromedp.Run(ctx,
			chromedp.Navigate(s.url),
			chromedp.WaitReady(s.wait, chromedp.ByQuery),
			chromedp.Sleep(1200*time.Millisecond),
			chromedp.FullScreenshot(&buf, 90),
		)
		if err != nil {
			log.Printf("skip %s: %v", s.name, err)
			continue
		}
		path := filepath.Join(*out, s.name)
		if err := os.WriteFile(path, buf, 0o644); err != nil {
			log.Fatalf("write %s: %v", path, err)
		}
		log.Printf("wrote %s (%d bytes)", path, len(buf))
	}

	// Person and meeting: discover first links from care/groups.
	var personURL, meetingURL string
	_ = chromedp.Run(ctx,
		chromedp.Navigate(homeURL+"groups"),
		chromedp.WaitReady("body", chromedp.ByQuery),
		chromedp.Sleep(800*time.Millisecond),
		chromedp.Evaluate(`(() => {
			const a = document.querySelector('a[href*="/group?id="], a[href*="/group/"]');
			return a ? a.href : '';
		})()`, &personURL),
	)
	// Reuse variable names carefully — fetch person from care page.
	var carePerson string
	_ = chromedp.Run(ctx,
		chromedp.Navigate(homeURL+"care"),
		chromedp.WaitReady("body", chromedp.ByQuery),
		chromedp.Sleep(800*time.Millisecond),
		chromedp.Evaluate(`(() => {
			const a = [...document.querySelectorAll('a[href]')].find(el => /person\?id=|\/person\//.test(el.getAttribute('href')||''));
			return a ? a.href : '';
		})()`, &carePerson),
	)
	if carePerson != "" {
		var buf []byte
		if err := chromedp.Run(ctx,
			chromedp.Navigate(carePerson),
			chromedp.WaitReady("body", chromedp.ByQuery),
			chromedp.Sleep(1000*time.Millisecond),
			chromedp.FullScreenshot(&buf, 90),
		); err == nil {
			path := filepath.Join(*out, "person.png")
			_ = os.WriteFile(path, buf, 0o644)
			log.Printf("wrote %s (%d bytes)", path, len(buf))
		}
	}

	_ = chromedp.Run(ctx,
		chromedp.Navigate(homeURL),
		chromedp.WaitReady("body", chromedp.ByQuery),
		chromedp.Sleep(800*time.Millisecond),
		chromedp.Evaluate(`(() => {
			const a = [...document.querySelectorAll('a[href]')].find(el => /meeting\?id=|\/meeting\//.test(el.getAttribute('href')||''));
			return a ? a.href : '';
		})()`, &meetingURL),
	)
	if meetingURL != "" {
		var buf []byte
		if err := chromedp.Run(ctx,
			chromedp.Navigate(meetingURL),
			chromedp.WaitReady("body", chromedp.ByQuery),
			chromedp.Sleep(1000*time.Millisecond),
			chromedp.FullScreenshot(&buf, 90),
		); err == nil {
			path := filepath.Join(*out, "meeting.png")
			_ = os.WriteFile(path, buf, 0o644)
			log.Printf("wrote %s (%d bytes)", path, len(buf))
		}
	} else if personURL != "" {
		// Fallback: open first group page and look for meeting there.
		var buf []byte
		var groupMeeting string
		_ = chromedp.Run(ctx,
			chromedp.Navigate(personURL),
			chromedp.WaitReady("body", chromedp.ByQuery),
			chromedp.Sleep(800*time.Millisecond),
			chromedp.Evaluate(`(() => {
				const a = [...document.querySelectorAll('a[href]')].find(el => /meeting\?id=|\/meeting\//.test(el.getAttribute('href')||''));
				return a ? a.href : '';
			})()`, &groupMeeting),
		)
		if groupMeeting != "" {
			if err := chromedp.Run(ctx,
				chromedp.Navigate(groupMeeting),
				chromedp.WaitReady("body", chromedp.ByQuery),
				chromedp.Sleep(1000*time.Millisecond),
				chromedp.FullScreenshot(&buf, 90),
			); err == nil {
				path := filepath.Join(*out, "meeting.png")
				_ = os.WriteFile(path, buf, 0o644)
				log.Printf("wrote %s (%d bytes)", path, len(buf))
			}
		} else {
			// Screenshot the group page as meeting stand-in context.
			if err := chromedp.Run(ctx, chromedp.FullScreenshot(&buf, 90)); err == nil {
				path := filepath.Join(*out, "meeting.png")
				_ = os.WriteFile(path, buf, 0o644)
				log.Printf("wrote %s from group page (%d bytes)", path, len(buf))
			}
		}
	}

	fmt.Println("done")
}

func trimSlash(s string) string {
	for len(s) > 0 && s[len(s)-1] == '/' {
		s = s[:len(s)-1]
	}
	return s
}
